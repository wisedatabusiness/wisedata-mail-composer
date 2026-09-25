<?php

declare(strict_types=1);

namespace WiseData\Mail\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WiseData\Mail\Client;
use WiseData\Mail\Exception\TransportException;
use WiseData\Mail\Http\CurlTransport;

/**
 * Quem controla parte de um cabeçalho não escreve um cabeçalho novo.
 *
 * Esta suíte existe por uma medição, não por precaução: uma chave de
 * idempotência com `\r\n` dentro chegou a um servidor local como TRÊS
 * cabeçalhos, e o do meio era `X-WiseData-Space` — o que decide em qual espaço
 * de envio a escrita cai. O libcurl repassa a linha como ela foi montada.
 *
 * A chave de idempotência é o valor mais exposto do pacote justamente porque a
 * documentação manda derivá-la de dado do domínio de quem integra
 * (`pedido-1234-confirmado`) — dado que muitas vezes veio de um formulário.
 */
final class HeaderInjectionTest extends TestCase
{
    /**
     * O pedido é recusado, e NADA sai da máquina.
     *
     * A asserção que importa é a segunda: recusar depois de a requisição ter
     * saído não seria correção nenhuma.
     */
    #[DataProvider('chavesEnvenenadas')]
    public function test_chave_de_idempotencia_com_controle_nao_sai_da_maquina(string $chave): void
    {
        $transporte = new FakeTransport;
        $cliente = new Client('wdm_abc_def', 'https://api.exemplo.test', transport: $transporte, retries: 0);

        try {
            $cliente->emails()->send(['to' => 'ana@exemplo.com'], idempotencyKey: $chave);
            $this->fail('A chave envenenada foi aceita.');
        } catch (TransportException $e) {
            $this->assertStringContainsString('caractere de controle', $e->getMessage());
        }

        $this->assertSame([], $transporte->chamadas, 'A requisição saiu mesmo com o cabeçalho envenenado.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function chavesEnvenenadas(): array
    {
        return [
            /* O caso medido: o segundo cabeçalho decide o espaço de envio. */
            'CRLF forjando o espaço' => ["pedido-1\r\nX-WiseData-Space: espaco-de-outro"],

            /* `\n` sozinho basta em parte dos servidores. */
            'LF sozinho' => ["pedido-1\nX-Injetado: sim"],

            /* O libcurl é escrito em C: o nulo trunca a linha. */
            'byte nulo' => ["pedido-1\0"],

            'CR sozinho' => ["pedido-1\rX-Injetado: sim"],
            'tab vertical' => ["pedido-1\x0b"],
        ];
    }

    /**
     * O slug do espaço vem de configuração, e configuração vem de lugar nenhum.
     *
     * `forSpace()` costuma receber um valor montado a partir da marca, do
     * inquilino ou de um parâmetro de rota — as três coisas que passam por
     * texto que alguém digitou.
     */
    public function test_espaco_com_quebra_de_linha_nao_sai_da_maquina(): void
    {
        $transporte = new FakeTransport;

        $cliente = (new Client('wdm_abc_def', 'https://api.exemplo.test', transport: $transporte, retries: 0))
            ->forSpace("marca-b\r\nAuthorization: Bearer wdm_da_vitima");

        $this->expectException(TransportException::class);

        try {
            $cliente->catalog()->lists();
        } finally {
            $this->assertSame([], $transporte->chamadas, 'A requisição saiu com o espaço envenenado.');
        }
    }

    /**
     * O último portão, para quem injeta o próprio transporte.
     *
     * O `Client` já confere, mas um produto que implementa `Transport` com
     * Guzzle passa por fora dele. Este teste prova que a régua também existe
     * onde a linha é montada — e ele estoura ANTES de qualquer rede, que é o
     * que permite exercitá-lo sem servidor.
     */
    public function test_o_transporte_padrao_recusa_por_conta_propria(): void
    {
        $this->expectException(TransportException::class);

        (new CurlTransport)->send(
            'GET',
            'https://api.exemplo.test/v1/lists',
            ['X-Qualquer' => "ok\r\nBcc: alguem@exemplo.com"],
            null,
        );
    }

    /**
     * O token não sai num dump.
     *
     * Ele não vaza por ataque: vaza por depuração. O `dd()` de quem está
     * caçando um erro, a tela de exceção do Laravel e a captura que essa pessoa
     * cola no chat do time são o caminho real — e todos passam pelo
     * `__debugInfo()`.
     */
    public function test_o_dump_do_cliente_nao_mostra_o_token(): void
    {
        $cliente = new Client('wdm_live_9pQmR3vTzK7wYbXnLcHdJs', 'https://api.exemplo.test');

        ob_start();
        var_dump($cliente);
        $despejo = (string) ob_get_clean();

        $this->assertStringNotContainsString('9pQmR3vTzK7wYbXnLcHdJs', $despejo);

        /* Mas dá para saber QUAL chave é — senão ninguém depura nada. */
        $this->assertStringContainsString('wdm_live', $despejo);
    }

    public function test_nome_de_cabecalho_fora_da_rfc_e_recusado(): void
    {
        $transporte = new FakeTransport;
        $cliente = new Client('wdm_abc_def', 'https://api.exemplo.test', transport: $transporte, retries: 0);

        $this->expectException(TransportException::class);

        /* O espaço antes dos dois-pontos já é outro cabeçalho para parte dos servidores. */
        $cliente->request('GET', 'lists', headers: ['X-Com Espaco' => 'valor']);
    }

    /**
     * A correção não pode ter comido o uso legítimo.
     *
     * Sem este, trocar a expressão por algo mais estrito passaria na suíte e
     * quebraria em produção o primeiro cliente com acento no nome de uma lista
     * ou espaço no identificador do pedido.
     */
    public function test_valor_legitimo_continua_passando(): void
    {
        $transporte = (new FakeTransport)->responde(202, ['data' => ['id' => 1]]);
        $cliente = new Client('wdm_abc_def', 'https://api.exemplo.test', transport: $transporte, retries: 0);

        $cliente->emails()->send(
            ['to' => 'ana@exemplo.com'],
            idempotencyKey: 'pedido nº 1.234/2026 — confirmação (José)',
        );

        $this->assertSame(
            'pedido nº 1.234/2026 — confirmação (José)',
            $transporte->ultima()['headers']['Idempotency-Key'],
        );
    }
}
