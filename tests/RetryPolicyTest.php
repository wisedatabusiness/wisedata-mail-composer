<?php

declare(strict_types=1);

namespace WiseData\Mail\Tests;

use PHPUnit\Framework\TestCase;
use WiseData\Mail\Client;
use WiseData\Mail\Exception\ApiException;

/**
 * Repetir não pode transformar uma falha em dois e-mails.
 *
 * Um 5xx NÃO diz que o servidor não fez. Ele pode ter aceitado o envio, debitado
 * a cota e caído ao responder — e a tentativa seguinte manda a mesma mensagem
 * para a mesma pessoa. O padrão do pacote é repetir duas vezes, então, sem esta
 * régua, `emails()->send()` sem chave de idempotência dispara até três e-mails a
 * partir de uma chamada.
 *
 * É o tipo de defeito que não aparece em desenvolvimento: precisa de um 5xx no
 * momento exato, e quem recebe a duplicata não é quem integra.
 */
final class RetryPolicyTest extends TestCase
{
    private function cliente(FakeTransport $transporte): Client
    {
        return new Client('wdm_abc_def', 'https://api.exemplo.test', transport: $transporte, retries: 2);
    }

    public function test_envio_sem_chave_de_idempotencia_nao_e_repetido(): void
    {
        $transporte = (new FakeTransport)->responde(500)->responde(202, ['data' => ['id' => 1]]);

        try {
            $this->cliente($transporte)->emails()->send(['to' => 'ana@exemplo.com']);
            $this->fail('O 500 deveria ter virado exceção.');
        } catch (ApiException $e) {
            $this->assertSame(500, $e->status);
        }

        $this->assertCount(
            1,
            $transporte->chamadas,
            'O POST de envio foi repetido sem chave de idempotência — é assim que nasce e-mail duplicado.',
        );
    }

    /**
     * Com a chave, a repetição volta — e precisa voltar.
     *
     * Desligar a repetição para todo POST seria a correção preguiçosa: quem faz
     * a coisa certa perderia a resiliência contra um 502 momentâneo, e o
     * incentivo se inverteria.
     */
    public function test_envio_com_chave_de_idempotencia_e_repetido(): void
    {
        $transporte = (new FakeTransport)->responde(500)->responde(202, ['data' => ['id' => 7]]);

        $dados = $this->cliente($transporte)
            ->emails()
            ->send(['to' => 'ana@exemplo.com'], idempotencyKey: 'pedido-1234-confirmado');

        $this->assertSame(7, $dados['id']);
        $this->assertCount(2, $transporte->chamadas);
    }

    /**
     * O `PUT` de contato é upsert: mandar duas vezes deixa a base igual.
     *
     * Aqui o teste protege contra o excesso de zelo — uma régua que olhasse "tem
     * corpo?" em vez de "é POST sem chave?" mataria a repetição de toda escrita
     * de contato, que é a chamada mais frequente do pacote.
     */
    public function test_upsert_de_contato_continua_sendo_repetido(): void
    {
        $transporte = (new FakeTransport)->responde(503)->responde(200, ['data' => ['id' => 3]]);

        $this->cliente($transporte)->contacts()->upsert(['email' => 'ana@exemplo.com']);

        $this->assertCount(2, $transporte->chamadas);
    }

    /**
     * Entrar em lista é POST, e é naturalmente idempotente — mas cai na régua.
     *
     * Documentado num teste porque é a consequência aceita da decisão de julgar
     * por MÉTODO e não por caminho: uma rota nova nasce protegida em vez de
     * depender de alguém lembrar de listá-la. O preço é este 429 que não é
     * repetido, e ele é barato.
     */
    public function test_entrar_em_lista_nao_e_repetido_e_isso_e_deliberado(): void
    {
        $transporte = (new FakeTransport)->responde(429)->responde(200, ['data' => []]);

        try {
            $this->cliente($transporte)->contacts()->addToLists('usr_9', [3]);
        } catch (ApiException) {
            /* O 429 chega a quem integra; o que se afirma aqui é a contagem. */
        }

        $this->assertCount(1, $transporte->chamadas);
    }
}
