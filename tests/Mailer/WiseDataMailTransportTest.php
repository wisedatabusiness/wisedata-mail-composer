<?php

declare(strict_types=1);

namespace WiseData\Mail\Tests\Mailer;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use WiseData\Mail\Client;
use WiseData\Mail\Mailer\WiseDataMailTransport;
use WiseData\Mail\Mailer\WiseDataMailTransportException;
use WiseData\Mail\Tests\FakeTransport;

/**
 * O transport que faz `MAIL_MAILER=wisedatamail` funcionar.
 *
 * Testado sem Laravel: o que pode dar errado aqui é a conversão da mensagem do
 * Symfony no corpo da chamada, e isso não precisa de framework nenhum para ser
 * provado. A cola do Laravel — um `config->set` e um `Mail::extend` — é mantida
 * burra justamente para não precisar de teste.
 */
final class WiseDataMailTransportTest extends TestCase
{
    public function test_a_mensagem_vira_o_corpo_que_a_api_espera(): void
    {
        $http = (new FakeTransport)->responde(202, ['data' => ['id' => 1, 'message_id' => 'abc@wisedatamail.com']]);

        $enviada = $this->transport($http)->send($this->email());

        $corpo = $http->ultima()['body'];

        $this->assertSame('ana@exemplo.com', $corpo['to'] ?? null);
        $this->assertSame('Ana', $corpo['to_name'] ?? null);
        $this->assertSame('Seu código', $corpo['subject'] ?? null);
        $this->assertSame('<p>123456</p>', $corpo['html'] ?? null);
        $this->assertSame('123456', $corpo['text'] ?? null);
        $this->assertSame('sistema@exemplo.com', $corpo['from'] ?? null);
        $this->assertSame('suporte@exemplo.com', $corpo['reply_to'] ?? null);

        $this->assertNotNull($enviada);
        $this->assertSame('abc@wisedatamail.com', $enviada->getMessageId());
    }

    /**
     * O identificador do provedor volta para o `SentMessage`.
     *
     * É o que permite à aplicação guardar o id e casá-lo com o webhook de
     * entrega. Sem isso o Laravel devolve o `Message-ID` que ele mesmo gerou —
     * que o WiseData Mail não conhece — e a correlação nunca acontece.
     */
    public function test_sem_identificador_na_resposta_o_envio_nao_quebra(): void
    {
        $http = (new FakeTransport)->responde(202, ['data' => ['id' => 7]]);

        $enviada = $this->transport($http)->send($this->email());

        $this->assertNotNull($enviada);
        $this->assertSame('7', $enviada->getMessageId());
    }

    /**
     * O anexo chega à rede com o conteúdo em base64 e o nome preservado.
     *
     * É a camada onde um campo some entre o `Mailable` e a requisição: sem a
     * asserção sobre o corpo EXATO, uma chave renomeada no payload passa em tudo
     * e o anexo simplesmente não sai.
     */
    public function test_anexo_vira_o_item_esperado_no_corpo(): void
    {
        $http = (new FakeTransport)->responde(202, ['data' => ['id' => 1]]);

        $this->transport($http)->send(
            $this->email()->attach('conteudo-do-pdf', 'nota.pdf', 'application/pdf')
        );

        $anexos = $http->ultima()['body']['attachments'] ?? [];

        $this->assertCount(1, $anexos);
        $this->assertSame('nota.pdf', $anexos[0]['filename']);
        $this->assertSame(base64_encode('conteudo-do-pdf'), $anexos[0]['content']);
        $this->assertSame('application/pdf', $anexos[0]['content_type']);
        $this->assertSame('attachment', $anexos[0]['disposition']);

        /*
         * `getContentId()` GERA um id quando não há. Lido fora do ramo embutido,
         * todo anexo comum ganharia `content_id` — e o inline deixaria de se
         * distinguir do resto.
         */
        $this->assertArrayNotHasKey('content_id', $anexos[0]);
    }

    /**
     * `embed()` vira inline com o `cid:` que o HTML referencia.
     *
     * É o logotipo no cabeçalho — o caso mais comum numa aplicação Laravel, e
     * justamente o que a versão anterior do pacote recusava.
     */
    public function test_imagem_embutida_vira_inline_com_content_id(): void
    {
        $http = (new FakeTransport)->responde(202, ['data' => ['id' => 1]]);

        $email = $this->email();
        $email->embed('bytes-do-png', 'logo.png', 'image/png');

        $this->transport($http)->send($email);

        $anexo = ($http->ultima()['body']['attachments'] ?? [])[0] ?? [];

        $this->assertSame('inline', $anexo['disposition'] ?? null);
        $this->assertNotEmpty($anexo['content_id'] ?? null);
    }

    /**
     * Anexo sem nome recusa, e NADA é enviado.
     *
     * O Symfony permite parte sem nome; a nossa API exige um. Inventar
     * `anexo-1.bin` entregaria ao destinatário um nome que ninguém escolheu, sem
     * nenhum indício de que foi inventado.
     */
    public function test_anexo_sem_nome_recusa_antes_de_qualquer_chamada(): void
    {
        $http = new FakeTransport;

        try {
            $this->transport($http)->send($this->email()->attach('conteudo'));
            $this->fail('o anexo sem nome precisa ser recusado');
        } catch (WiseDataMailTransportException $e) {
            $this->assertStringContainsString('NADA foi enviado', $e->getMessage());
        }

        $this->assertSame([], $http->chamadas, 'nenhuma requisição podia ter saído');
    }

    /** Sem anexo, a chave não viaja vazia. */
    public function test_mensagem_sem_anexo_nao_leva_a_chave(): void
    {
        $http = (new FakeTransport)->responde(202, ['data' => ['id' => 1]]);

        $this->transport($http)->send($this->email());

        $this->assertArrayNotHasKey('attachments', $http->ultima()['body']);
    }

    /** Cópia também recusa: uma mensagem transacional é de uma pessoa. */
    public function test_copia_recusa_antes_de_qualquer_chamada(): void
    {
        $http = new FakeTransport;

        $email = $this->email()->cc('financeiro@exemplo.com');

        $this->expectException(WiseDataMailTransportException::class);

        try {
            $this->transport($http)->send($email);
        } finally {
            $this->assertSame([], $http->chamadas);
        }
    }

    /**
     * A recusa da API vira erro que o Laravel entende, com o código preservado.
     *
     * `TransportExceptionInterface` é o que faz o job falhar, o evento
     * `MessageSendingFailed` disparar e a mensagem ir para `failed_jobs`. E
     * `$error` é o que permite à aplicação distinguir "acabou a cota" de "a
     * chave não tem escopo" sem casar string traduzida.
     */
    public function test_a_recusa_da_api_vira_erro_de_mailer_com_o_codigo(): void
    {
        $http = (new FakeTransport)->responde(403, [
            'error' => 'send_quota_exceeded',
            'message' => 'A cota acabou.',
        ]);

        try {
            $this->transport($http)->send($this->email());
            $this->fail('a recusa precisa virar exceção');
        } catch (WiseDataMailTransportException $e) {
            $this->assertInstanceOf(TransportExceptionInterface::class, $e);
            $this->assertSame('send_quota_exceeded', $e->error);
            $this->assertStringContainsString('A cota acabou.', $e->getMessage());
        }
    }

    /**
     * Corpo em recurso é normalizado.
     *
     * `getHtmlBody()` devolve `string|resource|null` — um Mailable com corpo
     * grande entrega um fluxo. Sem normalizar, o JSON da chamada levaria
     * "Resource id #5" no lugar do e-mail.
     */
    public function test_corpo_em_recurso_vira_texto(): void
    {
        $fluxo = fopen('php://temp', 'r+');
        $this->assertIsResource($fluxo);
        fwrite($fluxo, '<p>de um fluxo</p>');
        rewind($fluxo);

        $http = (new FakeTransport)->responde(202, ['data' => ['id' => 1]]);

        $this->transport($http)->send($this->email()->html($fluxo));

        $this->assertSame('<p>de um fluxo</p>', $http->ultima()['body']['html'] ?? null);
    }

    /**
     * O tipo vem do cabeçalho — e o cabeçalho não vaza para o destinatário.
     *
     * Ele é instrução nossa. Deixá-lo passar o poria no "ver original" de quem
     * recebe, junto com o resto dos cabeçalhos técnicos.
     */
    public function test_o_tipo_sai_do_cabecalho_e_o_cabecalho_e_removido(): void
    {
        $http = (new FakeTransport)->responde(202, ['data' => ['id' => 1]]);

        $email = $this->email();
        $email->getHeaders()->addTextHeader(WiseDataMailTransport::CABECALHO_TIPO, 'marketing');

        $this->transport($http)->send($email);

        $corpo = $http->ultima()['body'];

        $this->assertSame('marketing', $corpo['message_type'] ?? null);

        /*
         * O cabeçalho é instrução nossa: ele decide o tipo e some. Como os
         * `X-...` da aplicação SÃO encaminhados, deixá-lo passar o poria no "ver
         * original" da caixa de entrada de quem recebe.
         */
        $this->assertArrayNotHasKey('headers', $corpo);
    }

    /** O cabeçalho customizado da aplicação chega; o que o e-mail monta sozinho, não. */
    public function test_cabecalho_customizado_e_encaminhado_e_o_reservado_nao(): void
    {
        $http = (new FakeTransport)->responde(202, ['data' => ['id' => 1]]);

        $email = $this->email();
        $email->getHeaders()->addTextHeader('X-Pedido', '1234');

        $this->transport($http)->send($email);

        $cabecalhos = $http->ultima()['body']['headers'] ?? [];

        $this->assertSame(['X-Pedido' => '1234'], $cabecalhos);
    }

    /** Sem tipo declarado, a chave decide — a chamada nem manda o campo. */
    public function test_sem_tipo_declarado_a_chave_decide(): void
    {
        $http = (new FakeTransport)->responde(202, ['data' => ['id' => 1]]);

        $this->transport($http)->send($this->email());

        $this->assertArrayNotHasKey('message_type', $http->ultima()['body']);
    }

    /** Tipo escrito errado é barrado aqui, sem gastar uma ida de rede. */
    public function test_tipo_invalido_e_recusado_localmente(): void
    {
        $http = new FakeTransport;

        $email = $this->email();
        $email->getHeaders()->addTextHeader(WiseDataMailTransport::CABECALHO_TIPO, 'transacional');

        $this->expectException(WiseDataMailTransportException::class);

        try {
            $this->transport($http)->send($email);
        } finally {
            $this->assertSame([], $http->chamadas);
        }
    }

    /* ------------------------------------------------------------------ */

    private function transport(FakeTransport $http): WiseDataMailTransport
    {
        return new WiseDataMailTransport(new Client('wdm_abc_def', transport: $http, retries: 0));
    }

    private function email(): Email
    {
        return (new Email)
            ->from(new Address('sistema@exemplo.com', 'Sistema'))
            ->to(new Address('ana@exemplo.com', 'Ana'))
            ->replyTo('suporte@exemplo.com')
            ->subject('Seu código')
            ->text('123456')
            ->html('<p>123456</p>');
    }
}
