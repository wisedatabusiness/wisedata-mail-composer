<?php

declare(strict_types=1);

namespace WiseData\Mail\Tests;

use PHPUnit\Framework\TestCase;
use WiseData\Mail\Client;
use WiseData\Mail\Exception\ApiException;

/**
 * O recurso de envio: o que vai para a rede.
 *
 * As asserções são sobre método, endereço e corpo exatos — não sobre "chamou". O
 * defeito que este arquivo existe para pegar é o campo que some entre montar e
 * enviar, e ele não aparece em nenhum outro lugar.
 */
final class EmailsTest extends TestCase
{
    public function test_o_envio_manda_o_corpo_inteiro_para_a_rota_certa(): void
    {
        $transporte = (new FakeTransport)->responde(202, ['data' => ['id' => 42, 'status' => 'pending']]);

        $enviado = $this->cliente($transporte)->emails()->send([
            'to' => 'ana@exemplo.com',
            'subject' => 'Seu código',
            'html' => '<p>123456</p>',
        ]);

        $chamada = $transporte->ultima();

        $this->assertSame('POST', $chamada['method']);
        $this->assertSame('https://api.wisedatamail.com/v1/emails', $chamada['url']);
        $this->assertSame('ana@exemplo.com', $chamada['body']['to'] ?? null);
        $this->assertSame(42, $enviado['id'] ?? null);
    }

    /**
     * A chave de idempotência vai no cabeçalho, não no corpo.
     *
     * É o que permite reprocessar uma fila depois de uma queda sem duplicar
     * e-mail — e é a única proteção real contra isso, porque uma repetição de
     * rede é indistinguível de um pedido novo.
     */
    public function test_a_chave_de_idempotencia_viaja_no_cabecalho(): void
    {
        $transporte = (new FakeTransport)->responde(202, ['data' => ['id' => 1]]);

        $this->cliente($transporte)->emails()->send(
            ['to' => 'ana@exemplo.com', 'subject' => 'a', 'html' => '<p>a</p>'],
            idempotencyKey: 'pedido-1234-confirmado',
        );

        $chamada = $transporte->ultima();

        $this->assertSame('pedido-1234-confirmado', $chamada['headers']['Idempotency-Key'] ?? null);
        $this->assertArrayNotHasKey('idempotency_key', $chamada['body']);
    }

    /** Sem chave, o cabeçalho não é inventado — a API não deduplica nada. */
    public function test_sem_chave_o_cabecalho_nao_vai(): void
    {
        $transporte = (new FakeTransport)->responde(202, ['data' => ['id' => 1]]);

        $this->cliente($transporte)->emails()->send(['to' => 'ana@exemplo.com', 'subject' => 'a', 'html' => '<p>a</p>']);

        $this->assertArrayNotHasKey('Idempotency-Key', $transporte->ultima()['headers']);
    }

    /** Destinatário suprimido é 202, não erro: a chamada foi aceita e processada. */
    public function test_suprimido_volta_como_estado_e_nao_como_excecao(): void
    {
        $transporte = (new FakeTransport)->responde(202, ['data' => ['id' => 9, 'status' => 'suppressed']]);

        $enviado = $this->cliente($transporte)->emails()->send([
            'to' => 'devolveu@exemplo.com', 'subject' => 'a', 'html' => '<p>a</p>',
        ]);

        $this->assertSame('suppressed', $enviado['status'] ?? null);
    }

    /** Cota esgotada vira exceção com o código que a aplicação consegue ler. */
    public function test_cota_esgotada_vira_excecao_com_codigo(): void
    {
        $transporte = (new FakeTransport)->responde(403, [
            'error' => 'send_quota_exceeded',
            'message' => 'A cota acabou.',
            'upgrade_to' => 'scale',
        ]);

        try {
            $this->cliente($transporte)->emails()->send(['to' => 'ana@exemplo.com', 'subject' => 'a', 'html' => '<p>a</p>']);
            $this->fail('a recusa precisa virar exceção');
        } catch (ApiException $e) {
            $this->assertSame('send_quota_exceeded', $e->error);
            $this->assertSame(403, $e->status);
            $this->assertSame('scale', $e->extra['upgrade_to'] ?? null);
        }
    }

    private function cliente(FakeTransport $transporte): Client
    {
        return new Client('wdm_abc_def', transport: $transporte, retries: 0);
    }
}
