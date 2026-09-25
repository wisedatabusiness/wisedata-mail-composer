<?php

declare(strict_types=1);

namespace WiseData\Mail\Mailer;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\RuntimeException as MimeException;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use WiseData\Mail\Client;
use WiseData\Mail\Exception\ApiException;
use WiseData\Mail\Exception\WiseDataMailException;

/**
 * Faz `MAIL_MAILER=wisedatamail` funcionar.
 *
 * Com ele, migrar um produto para o WiseData Mail é uma linha no `.env`: todo
 * `Mailable`, toda `Notification` e todo `Mail::to()` que já existem continuam
 * funcionando sem uma alteração. É o motivo de a chave de API carregar um tipo
 * de mensagem padrão — sem isso, declarar "isto é transacional" exigiria mexer
 * em cada `Mailable` antigo.
 *
 * ## Por que estende `AbstractTransport`
 *
 * O `send()` dele já faz quatro coisas que não vale reescrever: monta o
 * `Envelope` validando remetente e destinatários, dispara o `MessageEvent` — que
 * é o que faz `Mail::alwaysTo()` funcionar —, aplica `setMaxPerSecond()` e
 * embrulha o resultado num `SentMessage`. Implementar `TransportInterface` direto
 * significaria reimplementar isso e quebrar o `alwaysTo` de homologação, que é
 * exatamente o defeito que manda e-mail de teste para cliente de verdade.
 *
 * ## Não confundir com `Contracts\Transport`
 *
 * O pacote tem duas coisas chamadas "transport", e elas não têm relação. Aquela
 * é o transporte HTTP da requisição à API REST; esta é o conceito de mailer do
 * Symfony. Daí o namespace próprio.
 */
final class WiseDataMailTransport extends AbstractTransport
{
    /**
     * Por onde a aplicação escolhe o tipo de uma mensagem específica.
     *
     * Existe para o caso raro. O caminho normal é não fazer nada e deixar a
     * chave decidir — ou apontar um segundo mailer para a mesma chave com outro
     * tipo, o que também não pede mudança em `Mailable` nenhum.
     */
    public const CABECALHO_TIPO = 'X-WiseData-Message-Type';

    /** @var list<string> */
    private const TIPOS = ['marketing', 'transactional'];

    public function __construct(
        private readonly Client $client,
        private readonly ?string $messageType = null,
    ) {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'wisedatamail';
    }

    protected function doSend(SentMessage $message): void
    {
        $email = $this->email($message);

        $this->recusarAnexo($email);
        $this->recusarVariosDestinatarios($email);

        $payload = array_filter([
            'to' => $this->primeiroEndereco($email->getTo()),
            'to_name' => $this->primeiroNome($email->getTo()),
            'subject' => $email->getSubject(),
            'html' => $this->corpo($email->getHtmlBody()),
            'text' => $this->corpo($email->getTextBody()),
            'from' => $this->primeiroEndereco($email->getFrom()),
            'reply_to' => $this->primeiroEndereco($email->getReplyTo()),
            'message_type' => $this->tipo($email),
            'headers' => $this->cabecalhosExtras($email),
        ], static fn (mixed $valor): bool => $valor !== null && $valor !== '' && $valor !== []);

        $dados = $this->enviar($payload);

        /*
         * O identificador do provedor volta para o `SentMessage`, e é o que
         * permite à aplicação guardar o id e casá-lo com o webhook de entrega
         * depois. Sem isto o Laravel devolve o `Message-ID` que ele mesmo gerou
         * — que o WiseData Mail não conhece —, e a correlação nunca acontece.
         */
        $id = $dados['message_id'] ?? $dados['id'] ?? null;

        if (is_string($id) || is_int($id)) {
            /*
             * No aceite o identificador do PROVEDOR ainda não existe — o envio é
             * assíncrono do outro lado —, então o que volta normalmente é o id
             * do WiseData Mail. É ele que serve de qualquer forma: é por ele que
             * os eventos de entrega e devolução serão correlacionados, e é o que
             * a aplicação deve guardar ao lado do próprio registro.
             */
            $message->setMessageId((string) $id);
        }
    }

    private function email(SentMessage $message): Email
    {
        try {
            return MessageConverter::toEmail($message->getOriginalMessage());
        } catch (MimeException $e) {
            /*
             * Embrulhado porque a exceção do Mime NÃO é
             * `TransportExceptionInterface`: solta, ela chegaria ao mailer da
             * aplicação como erro inesperado, fora do contrato que o Laravel
             * espera de um transport.
             */
            throw new WiseDataMailTransportException(
                'Esta mensagem não é um e-mail que o WiseData Mail saiba converter: '.$e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * Recusa alto, antes de qualquer chamada de rede.
     *
     * Entregar a mensagem sem o anexo seria pior do que falhar: o aviso de nota
     * fiscal chegaria sem a nota, o boleto sem o boleto, e ninguém perceberia até
     * o cliente reclamar. `getAttachments()` inclui as partes embutidas por
     * `embed()` — o logotipo no cabeçalho é o caso mais comum —, e recusar é o
     * certo também para elas: o HTML referencia `cid:...` e, sem a parte, a
     * imagem vira ícone quebrado em todo cliente de e-mail.
     */
    private function recusarAnexo(Email $email): void
    {
        $anexos = $email->getAttachments();

        if ($anexos === []) {
            return;
        }

        throw new WiseDataMailTransportException(sprintf(
            'O WiseData Mail ainda não envia anexo, e esta mensagem tem %d. NADA foi enviado — entregar '
            .'o e-mail sem o anexo esconderia o problema até o destinatário reclamar. Publique o arquivo '
            .'e mande o link, ou use outro mailer para esta mensagem.',
            count($anexos),
        ));
    }

    /**
     * Uma mensagem, uma pessoa.
     *
     * A API aceita um destinatário por chamada: mensagem transacional é de
     * alguém — o recibo é dele, o código é dele. Quebrar em N chamadas aqui
     * seria a saída óbvia e é a errada: com três destinatários, uma segunda
     * chamada recusada deixaria o primeiro já tendo recebido, e a retentativa do
     * job o faria receber de novo. Falha parcial não tem representação num
     * `doSend()`, que ou termina ou lança.
     *
     * Recusar é a escolha previsível. Quem precisa alcançar várias pessoas faz
     * um `Mail::to()` por pessoa — que é o que o transacional significa — ou
     * monta uma campanha, que é a ferramenta para falar com um grupo.
     */
    private function recusarVariosDestinatarios(Email $email): void
    {
        $extras = count($email->getCc()) + count($email->getBcc());

        if (count($email->getTo()) <= 1 && $extras === 0) {
            return;
        }

        throw new WiseDataMailTransportException(sprintf(
            'O WiseData Mail envia para um destinatário por mensagem, e esta tem %d em "para" e %d em '
            .'cópia. NADA foi enviado. Faça um envio por pessoa, ou use uma campanha para falar com o grupo.',
            count($email->getTo()),
            $extras,
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function enviar(array $payload): array
    {
        try {
            return $this->client->emails()->send($payload);
        } catch (ApiException $e) {
            throw new WiseDataMailTransportException(
                "O WiseData Mail recusou o envio ({$e->error}): {$e->getMessage()}",
                $e->error,
                $e,
            );
        } catch (WiseDataMailException $e) {
            /* A raiz cobre a falha de rede e qualquer subclasse futura. */
            throw new WiseDataMailTransportException(
                'A requisição de envio não chegou ao WiseData Mail: '.$e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * O tipo desta mensagem, se alguém o declarou.
     *
     * A ordem é cabeçalho da mensagem → configuração do mailer → nada, e o
     * "nada" é o caminho normal: omitir a chave faz a API usar o tipo padrão da
     * credencial, que é o que mantém o atrito em zero para quem está migrando.
     *
     * O cabeçalho é REMOVIDO depois de lido: ele é instrução nossa e não tem por
     * que aparecer no "ver original" da caixa de entrada de quem recebe.
     */
    private function tipo(Email $email): ?string
    {
        $cabecalhos = $email->getHeaders();
        $tipo = $this->messageType;

        if ($cabecalhos->has(self::CABECALHO_TIPO)) {
            $doCabecalho = $cabecalhos->get(self::CABECALHO_TIPO)?->getBodyAsString();
            $cabecalhos->remove(self::CABECALHO_TIPO);

            if (is_string($doCabecalho) && trim($doCabecalho) !== '') {
                $tipo = trim($doCabecalho);
            }
        }

        if ($tipo === null) {
            return null;
        }

        if (! in_array($tipo, self::TIPOS, true)) {
            /*
             * Recusado aqui, e não pela API: gastar uma ida de rede para saber
             * que alguém escreveu "transacional" em português é desperdício, e o
             * erro fica longe de onde o valor foi escrito.
             */
            throw new WiseDataMailTransportException(sprintf(
                'Tipo de mensagem inválido: "%s". Os valores aceitos são %s.',
                $tipo,
                implode(' e ', self::TIPOS),
            ));
        }

        return $tipo;
    }

    /**
     * Os cabeçalhos `X-...` que a aplicação pôs na mensagem.
     *
     * Só a família `X-`, e não todos: o resto são os cabeçalhos que o e-mail
     * monta sozinho — `From`, `To`, `Date`, `Message-ID`, `MIME-Version` —, e
     * encaminhá-los faria a API recusar a mensagem inteira por cabeçalho
     * reservado. Perder o cabeçalho customizado da aplicação em silêncio seria o
     * outro extremo, e é o mais difícil de perceber.
     *
     * A família `X-SES-` fica de fora por ser reservada da infraestrutura de
     * envio. A API a recusa de qualquer forma; barrar aqui só evita gastar uma
     * ida de rede para descobrir isso.
     *
     * @return array<string, string>
     */
    private function cabecalhosExtras(Email $email): array
    {
        $extras = [];

        foreach ($email->getHeaders()->all() as $cabecalho) {
            $nome = $cabecalho->getName();
            $minusculo = strtolower($nome);

            if (! str_starts_with($minusculo, 'x-') || str_starts_with($minusculo, 'x-ses-')) {
                continue;
            }

            $extras[$nome] = $cabecalho->getBodyAsString();
        }

        return $extras;
    }

    /**
     * O corpo, normalizado.
     *
     * `getHtmlBody()` e `getTextBody()` devolvem `string|resource|null`: um
     * `Mailable` com corpo grande, ou montado a partir de um fluxo, entrega um
     * recurso. Sem isto, o `json_encode` do corpo falharia — ou pior, gravaria
     * "Resource id #5" no e-mail.
     */
    private function corpo(mixed $valor): ?string
    {
        if (is_resource($valor)) {
            $conteudo = stream_get_contents($valor);

            return is_string($conteudo) ? $conteudo : null;
        }

        return is_string($valor) ? $valor : null;
    }

    /**
     * @param  list<Address>  $enderecos
     */
    private function primeiroEndereco(array $enderecos): ?string
    {
        return ($enderecos[0] ?? null)?->getAddress();
    }

    /**
     * @param  list<Address>  $enderecos
     */
    private function primeiroNome(array $enderecos): ?string
    {
        /* `getName()` devolve string vazia quando não há nome, nunca `null`. */
        $nome = ($enderecos[0] ?? null)?->getName() ?? '';

        return $nome === '' ? null : $nome;
    }
}
