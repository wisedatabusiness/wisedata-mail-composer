<?php

declare(strict_types=1);

namespace WiseData\Mail\Mailer;

use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\RuntimeException as MimeException;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use WiseData\Mail\Client;
use WiseData\Mail\Exception\ApiException;
use WiseData\Mail\Exception\WiseDataMailException;
use WiseData\Mail\Validation\SendRules;

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

    /**
     * @param  bool  $useAccountSender  não manda o `from` da mensagem: a API usa o
     *                                  remetente padrão da conta. Sem isto, o `from`
     *                                  (no Laravel, o `MAIL_FROM_ADDRESS`) vai na
     *                                  chamada e precisa ser um remetente verificado
     *                                  no WiseData Mail, ou a API recusa com 422.
     */
    public function __construct(
        private readonly Client $client,
        private readonly ?string $messageType = null,
        private readonly bool $useAccountSender = false,
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

        $this->recusarVariosDestinatarios($email);

        $payload = array_filter([
            'to' => $this->primeiroEndereco($email->getTo()),
            'to_name' => $this->primeiroNome($email->getTo()),
            'subject' => $email->getSubject(),
            'html' => $this->corpo($email->getHtmlBody()),
            'text' => $this->corpo($email->getTextBody()),
            /*
             * Só o endereço: o nome exibido é o do cadastro do remetente no
             * WiseData Mail, e a API não aceita outro.
             */
            'from' => $this->useAccountSender ? null : $this->primeiroEndereco($email->getFrom()),
            'reply_to' => $this->primeiroEndereco($email->getReplyTo()),
            'message_type' => $this->tipo($email),
            'headers' => $this->cabecalhosExtras($email),

            /*
             * As `tags` e os `metadata` do `Envelope` do Laravel chegam como
             * `TagHeader`/`MetadataHeader`. Vão nos campos próprios, como fazem
             * os bridges oficiais do Symfony (SendGrid, Postmark, Mailgun):
             * como cabeçalho, `X-Metadata-user_id` era recusado pela API e,
             * se passasse, iria visível a quem recebe.
             */
            'tags' => $this->tags($email),
            'metadata' => $this->metadados($email),

            /*
             * Convertido ANTES da rede: o `AttachmentConverter` recusa o que não
             * pode sair, e uma mensagem com dez megabytes que o servidor
             * rejeitaria não tem por que subir.
             */
            'attachments' => AttachmentConverter::fromEmail($email),
        ], static fn (mixed $valor): bool => $valor !== null && $valor !== '' && $valor !== []);

        $this->recusarForaDasRegras($payload);

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
            /* `X-Tag` e `X-Metadata-*` viajam nos campos próprios, nunca como cabeçalho. */
            if ($cabecalho instanceof TagHeader || $cabecalho instanceof MetadataHeader) {
                continue;
            }

            $nome = $cabecalho->getName();
            $minusculo = strtolower($nome);

            if (! str_starts_with($minusculo, 'x-') || str_starts_with($minusculo, SendRules::RESERVED_HEADER_PREFIX)) {
                continue;
            }

            $extras[$nome] = $cabecalho->getBodyAsString();
        }

        return $extras;
    }

    /**
     * Repetida sai uma vez só: o `Envelope` do Laravel trata tag como conjunto,
     * e a API recusaria a lista inteira por causa da segunda.
     *
     * @return list<string>
     */
    private function tags(Email $email): array
    {
        $tags = [];

        foreach ($email->getHeaders()->all() as $cabecalho) {
            if ($cabecalho instanceof TagHeader) {
                $tags[] = $cabecalho->getValue();
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * @return array<string, string>
     */
    private function metadados(Email $email): array
    {
        $metadados = [];

        foreach ($email->getHeaders()->all() as $cabecalho) {
            if ($cabecalho instanceof MetadataHeader) {
                $metadados[$cabecalho->getKey()] = $cabecalho->getValue();
            }
        }

        return $metadados;
    }

    /**
     * As mesmas regras da API, antes da rede.
     *
     * Num job de fila, a recusa da API vira uma retentativa por mensagem — foram
     * milhares em minutos no incidente que motivou isto. Recusando aqui, o erro
     * diz qual campo está errado e nenhuma requisição sai.
     *
     * @param  array<string, mixed>  $payload
     */
    private function recusarForaDasRegras(array $payload): void
    {
        $violacoes = SendRules::violations($payload);

        if ($violacoes === []) {
            return;
        }

        $linhas = [];

        foreach ($violacoes as $campo => $mensagens) {
            $linhas[] = "{$campo}: ".implode(' ', $mensagens);
        }

        throw new WiseDataMailTransportException(
            'A mensagem não passa nas regras do WiseData Mail e NADA foi enviado. '.implode(' | ', $linhas),
            'validation_failed',
        );
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
