<?php

declare(strict_types=1);

namespace WiseData\Mail\Tests;

use PHPUnit\Framework\TestCase;
use WiseData\Mail\Client;
use WiseData\Mail\Exception\ApiException;
use WiseData\Mail\Webhook\WebhookPayload;

/**
 * Espaços, domínios e remetentes — o que cada método manda para a rede.
 *
 * Os erros caros aqui são de ENDEREÇO, não de corpo: o espaço criado com o
 * cabeçalho de espaço (a API recusa), o domínio do cliente cadastrado na conta
 * principal (sem o cabeçalho), e o `verify` repetido sozinho contra o limite
 * de um por minuto.
 */
final class PlatformResourcesTest extends TestCase
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

    /**
     * Mesmo de um cliente já apontado para um espaço: a API responde 400
     * `space_not_applicable` a qualquer rota de espaço com o cabeçalho.
     */
    public function test_espaco_sai_sempre_pela_conta(): void
    {
        $transporte = (new FakeTransport)->responde(201, ['data' => ['slug' => 'avcom-k3m9qz']]);

        $espaco = $this->cliente($transporte, space: 'outro-cliente')->spaces()->upsert('cliente_812', 'Avcom');

        $chamada = $transporte->ultima();
        $this->assertSame('PUT', $chamada['method']);
        $this->assertSame('https://api.exemplo.test/v1/spaces', $chamada['url']);
        $this->assertArrayNotHasKey('X-WiseData-Space', $chamada['headers']);
        $this->assertSame('avcom-k3m9qz', $espaco['slug']);
    }

    /**
     * `send_limit` vai sempre, inclusive nulo: a API substitui, e é assim que
     * se tira o teto. Omitir a chave por "limpeza" mudaria o significado.
     */
    public function test_upsert_de_espaco_manda_o_limite_mesmo_nulo(): void
    {
        $transporte = (new FakeTransport)->responde(200, ['data' => []])->responde(200, ['data' => []]);
        $espacos = $this->cliente($transporte)->spaces();

        $espacos->upsert('cliente_812', 'Avcom', 20000);
        $this->assertSame(['external_id' => 'cliente_812', 'name' => 'Avcom', 'send_limit' => 20000], $transporte->ultima()['body']);

        $espacos->upsert('cliente_812', 'Avcom');
        $this->assertSame(['external_id' => 'cliente_812', 'name' => 'Avcom', 'send_limit' => null], $transporte->ultima()['body']);
    }

    public function test_find_e_delete_de_espaco_escapam_o_identificador(): void
    {
        $transporte = (new FakeTransport)->responde(200, ['data' => []])->responde(204);
        $espacos = $this->cliente($transporte, space: 'x')->spaces();

        $espacos->find('crm/812');
        $this->assertSame('https://api.exemplo.test/v1/spaces/crm%2F812', $transporte->ultima()['url']);

        $espacos->delete('crm/812');
        $this->assertSame('DELETE', $transporte->ultima()['method']);
        $this->assertSame('https://api.exemplo.test/v1/spaces/crm%2F812', $transporte->ultima()['url']);
        $this->assertArrayNotHasKey('X-WiseData-Space', $transporte->ultima()['headers']);
    }

    /** O domínio do cliente vai PARA O ESPAÇO dele — sem o cabeçalho, cairia na conta. */
    public function test_dominio_e_cadastrado_no_espaco_do_cliente(): void
    {
        $transporte = (new FakeTransport)->responde(201, ['data' => ['domain' => 'avcom.com.co', 'records' => [['purpose' => 'dmarc']]]]);

        $dominio = $this->cliente($transporte)->forSpace('avcom-k3m9qz')->domains()->register('avcom.com.co');

        $chamada = $transporte->ultima();
        $this->assertSame('PUT', $chamada['method']);
        $this->assertSame('https://api.exemplo.test/v1/domains', $chamada['url']);
        $this->assertSame(['domain' => 'avcom.com.co'], $chamada['body']);
        $this->assertSame('avcom-k3m9qz', $chamada['headers']['X-WiseData-Space']);
        $this->assertSame([['purpose' => 'dmarc']], $dominio['records']);
    }

    public function test_find_verify_e_delete_de_dominio(): void
    {
        $transporte = (new FakeTransport)
            ->responde(200, ['data' => []])
            ->responde(200, ['data' => ['checking' => true]])
            ->responde(204);
        $dominios = $this->cliente($transporte, space: 'avcom')->domains();

        $dominios->find('avcom.com.co');
        $this->assertSame('GET', $transporte->ultima()['method']);
        $this->assertSame('https://api.exemplo.test/v1/domains/avcom.com.co', $transporte->ultima()['url']);

        $this->assertSame(['checking' => true], $dominios->verify('avcom.com.co'));
        $this->assertSame('POST', $transporte->ultima()['method']);
        $this->assertSame('https://api.exemplo.test/v1/domains/avcom.com.co/verify', $transporte->ultima()['url']);

        $dominios->delete('avcom.com.co');
        $this->assertSame('DELETE', $transporte->ultima()['method']);
    }

    /**
     * O 429 do `verify` diz "espere um minuto". Repetir sozinho só gastaria as
     * tentativas contra o mesmo limite — e quem integra precisa do tempo para
     * mostrar ao cliente.
     */
    public function test_verify_cedo_demais_nao_repete_e_devolve_a_espera(): void
    {
        $transporte = (new FakeTransport)->responde(429, ['error' => 'rate_limited', 'message' => 'Aguarde.', 'retry_after' => 60]);

        try {
            $this->cliente($transporte, space: 'avcom', retries: 2)->domains()->verify('avcom.com.co');
            $this->fail('o 429 precisa virar exceção');
        } catch (ApiException $e) {
            $this->assertSame('rate_limited', $e->error);
            $this->assertSame(60, $e->retryAfter());
        }

        $this->assertCount(1, $transporte->chamadas);
    }

    public function test_provedor_indisponivel_traz_a_espera(): void
    {
        $transporte = (new FakeTransport)->responde(503, ['error' => 'provider_unavailable', 'message' => 'Tente de novo.', 'retry_after' => 5]);

        try {
            $this->cliente($transporte, space: 'avcom')->domains()->register('avcom.com.co');
            $this->fail('o 503 precisa virar exceção');
        } catch (ApiException $e) {
            $this->assertSame('provider_unavailable', $e->error);
            $this->assertSame(503, $e->status);
            $this->assertSame(5, $e->retryAfter());
        }
    }

    public function test_retry_after_e_nulo_quando_a_api_nao_pede_espera(): void
    {
        $transporte = (new FakeTransport)->responde(404, ['error' => 'domain_not_found', 'message' => 'Não achei.']);

        try {
            $this->cliente($transporte)->domains()->find('nada.test');
            $this->fail('o 404 precisa virar exceção');
        } catch (ApiException $e) {
            $this->assertNull($e->retryAfter());
        }
    }

    /** `reply_to` nulo vai no corpo: a API substitui, e é assim que se tira a resposta. */
    public function test_upsert_de_remetente_manda_o_corpo_inteiro(): void
    {
        $transporte = (new FakeTransport)->responde(201, ['data' => ['verified' => true]]);

        $remetente = $this->cliente($transporte, space: 'avcom')->senders()->upsert('encuestas@avcom.com.co', 'Avcom', 'Pesquisas');

        $chamada = $transporte->ultima();
        $this->assertSame('PUT', $chamada['method']);
        $this->assertSame('https://api.exemplo.test/v1/senders', $chamada['url']);
        $this->assertSame([
            'from_email' => 'encuestas@avcom.com.co',
            'from_name' => 'Avcom',
            'reply_to' => null,
            'label' => 'Pesquisas',
        ], $chamada['body']);
        $this->assertSame('avcom', $chamada['headers']['X-WiseData-Space']);
        $this->assertTrue($remetente['verified']);
    }

    public function test_find_e_delete_de_remetente_escapam_o_endereco(): void
    {
        $transporte = (new FakeTransport)->responde(200, ['data' => []])->responde(204);
        $remetentes = $this->cliente($transporte, space: 'avcom')->senders();

        $remetentes->find('encuestas+nps@avcom.com.co');
        $this->assertSame('https://api.exemplo.test/v1/senders/encuestas%2Bnps%40avcom.com.co', $transporte->ultima()['url']);

        $remetentes->delete('encuestas+nps@avcom.com.co');
        $this->assertSame('DELETE', $transporte->ultima()['method']);
    }

    public function test_listas_paginam(): void
    {
        $transporte = (new FakeTransport)->responde(200, ['data' => [], 'meta' => []])->responde(200, ['data' => []])->responde(200, ['data' => []]);
        $cliente = $this->cliente($transporte);

        $cliente->spaces()->list(2, 50);
        $this->assertSame('https://api.exemplo.test/v1/spaces?page=2&per_page=50', $transporte->ultima()['url']);

        $cliente->domains()->list();
        $this->assertSame('https://api.exemplo.test/v1/domains?page=1&per_page=25', $transporte->ultima()['url']);

        /* A API não pagina remetentes: mandar `page` sugeriria que há uma página 2. */
        $cliente->senders()->list();
        $this->assertSame('https://api.exemplo.test/v1/senders', $transporte->ultima()['url']);
    }

    /** Evento de mensagem continua sem `domain`; o de domínio traz o retrato tipado. */
    public function test_payload_le_o_retrato_do_dominio(): void
    {
        $payload = WebhookPayload::parse((string) json_encode(['events' => [
            ['id' => 1, 'type' => 'delivered', 'email' => 'ana@exemplo.com', 'domain' => null],
            ['id' => 2, 'type' => 'domain.failed', 'email' => null, 'space' => 'avcom', 'domain' => [
                'domain' => 'avcom.com.co', 'ready_to_send' => false, 'verified_at' => null, 'failed_records' => ['dkim', 'dmarc', 7],
            ]],
            ['id' => 3, 'type' => 'domain.verified', 'domain' => 'formato-inesperado'],
        ]]));

        [$mensagem, $falhou, $estranho] = $payload->events;

        $this->assertNull($mensagem->domain);
        $this->assertFalse($mensagem->isAboutDomain());

        $this->assertTrue($falhou->isAboutDomain());
        $this->assertSame('', $falhou->email);
        $this->assertSame('avcom.com.co', $falhou->domain?->domain);
        $this->assertSame(['dkim', 'dmarc'], $falhou->domain->failedRecords, 'item que não é texto é descartado.');

        $this->assertTrue($estranho->isAboutDomain());
        $this->assertNull($estranho->domain, 'formato inesperado não derruba a leitura do lote.');
    }
}
