<?php

declare(strict_types=1);

namespace WiseData\Mail\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WiseData\Mail\Client;
use WiseData\Mail\Exception\ApiException;
use WiseData\Mail\Exception\TransportException;

/**
 * O que o cliente faz com toda chamada, antes de o recurso entrar em cena.
 */
final class ClientTest extends TestCase
{
    private function cliente(FakeTransport $transporte, ?string $space = null, int $retries = 0): Client
    {
        return new Client(
            token: 'wdm_abc_def',
            baseUrl: 'https://api.exemplo.test',
            space: $space,
            retries: $retries,
            transport: $transporte,
        );
    }

    public function test_a_url_carrega_o_prefixo_v1_e_a_query(): void
    {
        $transporte = (new FakeTransport)->responde(200, ['data' => []]);

        $this->cliente($transporte)->contacts()->list(page: 2, perPage: 10);

        $this->assertSame(
            'https://api.exemplo.test/v1/contacts?page=2&per_page=10',
            $transporte->ultima()['url'],
        );
    }

    public function test_o_token_vai_no_authorization_e_o_espaco_no_cabecalho_proprio(): void
    {
        $transporte = (new FakeTransport)->responde(200, ['data' => []]);

        $this->cliente($transporte, space: 'marca-x')->catalog()->fields();

        $cabecalhos = $transporte->ultima()['headers'];

        $this->assertSame('Bearer wdm_abc_def', $cabecalhos['Authorization']);
        $this->assertSame('marca-x', $cabecalhos['X-WiseData-Space']);
    }

    /**
     * Sem espaço configurado, o cabeçalho NÃO vai — mandá-lo vazio faria a API
     * tentar resolver um slug em branco e recusar a chamada.
     */
    public function test_sem_espaco_o_cabecalho_nao_e_enviado(): void
    {
        $transporte = (new FakeTransport)->responde(200, ['data' => []]);

        $this->cliente($transporte)->catalog()->fields();

        $this->assertArrayNotHasKey('X-WiseData-Space', $transporte->ultima()['headers']);
    }

    /** `forSpace()` devolve outro cliente; o original continua onde estava. */
    public function test_for_space_nao_altera_o_cliente_original(): void
    {
        $transporte = (new FakeTransport)->responde(200, ['data' => []])->responde(200, ['data' => []]);

        $original = $this->cliente($transporte, space: 'marca-a');
        $outro = $original->forSpace('marca-b');

        $outro->catalog()->fields();
        $this->assertSame('marca-b', $transporte->ultima()['headers']['X-WiseData-Space']);

        $original->catalog()->fields();
        $this->assertSame('marca-a', $transporte->ultima()['headers']['X-WiseData-Space']);
    }

    /**
     * O corpo do erro vira exceção com o CÓDIGO preservado — é contra ele que
     * quem integra escreve o `if`, e não contra a frase, que é traduzida.
     */
    public function test_erro_da_api_vira_excecao_com_o_codigo_e_os_extras(): void
    {
        $transporte = (new FakeTransport)->responde(403, [
            'error' => 'insufficient_scope',
            'message' => 'Esta chave não tem o escopo necessário.',
            'required_scope' => 'contacts.write',
        ]);

        try {
            $this->cliente($transporte)->contacts()->upsert(['email' => 'ana@exemplo.com']);
            $this->fail('A chamada deveria ter lançado ApiException.');
        } catch (ApiException $e) {
            $this->assertSame('insufficient_scope', $e->error);
            $this->assertSame(403, $e->status);
            $this->assertSame('contacts.write', $e->requiredScope());
        }
    }

    /** Resposta sem JSON legível (um 502 de balanceador) ainda vira exceção útil. */
    public function test_erro_sem_corpo_json_ainda_diz_o_status(): void
    {
        $transporte = (new FakeTransport)->responde(502);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('502');

        $this->cliente($transporte)->catalog()->lists();
    }

    /** 429 e 5xx são repetidos; o resultado da última tentativa é o que vale. */
    public function test_429_e_repetido_e_o_sucesso_seguinte_vale(): void
    {
        $transporte = (new FakeTransport)
            ->responde(429, ['error' => 'too_many'])
            ->responde(200, ['data' => [['key' => 'plano_atual']]]);

        $campos = $this->cliente($transporte, retries: 1)->catalog()->fields();

        $this->assertCount(2, $transporte->chamadas);
        $this->assertSame('plano_atual', $campos[0]['key']);
    }

    /**
     * 422 NÃO é repetido: repetir um corpo inválido devolve o mesmo 422 e só
     * gasta o balde de limite da chave.
     */
    public function test_422_nao_e_repetido(): void
    {
        $transporte = (new FakeTransport)->responde(422, ['error' => 'unknown_field', 'field' => 'x']);

        try {
            $this->cliente($transporte, retries: 3)->contacts()->upsert(['email' => 'ana@exemplo.com']);
        } catch (ApiException) {
            // esperado
        }

        $this->assertCount(1, $transporte->chamadas);
    }

    /** Falha de rede em todas as tentativas vira `TransportException`, não `ApiException`. */
    public function test_falha_de_rede_persistente_vira_transport_exception(): void
    {
        $transporte = (new FakeTransport)->falha()->falha();

        $this->expectException(TransportException::class);

        $this->cliente($transporte, retries: 1)->catalog()->fields();
    }

    public function test_token_vazio_e_recusado_na_construcao(): void
    {
        $this->expectException(TransportException::class);

        new Client(token: '   ');
    }

    /**
     * Endereço sem TLS é recusado ANTES de qualquer chamada.
     *
     * O token vai em toda requisição: um `WISEDATA_MAIL_URL` de desenvolvimento
     * que sobreviveu ao deploy o entregaria em claro a quem estivesse no
     * caminho, e nada na aplicação indicaria isso.
     */
    #[DataProvider('enderecosSemTls')]
    public function test_endereco_sem_tls_e_recusado(string $baseUrl): void
    {
        $this->expectException(TransportException::class);

        new Client(token: 'wdm_abc_def', baseUrl: $baseUrl);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function enderecosSemTls(): array
    {
        return [
            'http comum' => ['http://api.exemplo.com'],
            'http num host que só PARECE local' => ['http://localhost.exemplo.com'],
            'sem esquema' => ['api.exemplo.com'],
            'ftp' => ['ftp://api.exemplo.com'],
        ];
    }

    /**
     * O endereço local continua em http.
     *
     * Exigir certificado na máquina de quem desenvolve faria todo mundo desligar
     * a verificação — e aí ela não protegeria mais nada em produção.
     */
    #[DataProvider('enderecosLocais')]
    public function test_endereco_local_pode_usar_http(string $baseUrl): void
    {
        $cliente = new Client(token: 'wdm_abc_def', baseUrl: $baseUrl, transport: new FakeTransport);

        $this->assertInstanceOf(Client::class, $cliente);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function enderecosLocais(): array
    {
        return [
            'localhost' => ['http://localhost:8000'],
            'loopback' => ['http://127.0.0.1:8000'],
            'domínio .test do Herd/Valet' => ['http://meu-produto.test'],
            'https continua valendo' => ['https://api.exemplo.com'],
        ];
    }
}
