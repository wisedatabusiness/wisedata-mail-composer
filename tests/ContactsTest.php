<?php

declare(strict_types=1);

namespace WiseData\Mail\Tests;

use PHPUnit\Framework\TestCase;
use WiseData\Mail\Client;

/**
 * O que cada método de contato manda para a rede.
 *
 * É a camada onde um campo somido em silêncio se esconde: o método aceita o
 * array, devolve sem erro, e o dado nunca chega.
 */
final class ContactsTest extends TestCase
{
    private function cliente(FakeTransport $transporte): Client
    {
        return new Client(
            token: 'wdm_abc_def',
            baseUrl: 'https://api.exemplo.test',
            retries: 0,
            transport: $transporte,
        );
    }

    public function test_upsert_manda_o_corpo_inteiro_em_put(): void
    {
        $transporte = (new FakeTransport)->responde(201, ['data' => ['id' => 1]]);

        $this->cliente($transporte)->contacts()->upsert([
            'external_id' => 'usr_9',
            'email' => 'ana@exemplo.com',
            'fields' => ['plano_atual' => 'pro'],
            'lists' => [3],
        ]);

        $chamada = $transporte->ultima();

        $this->assertSame('PUT', $chamada['method']);
        $this->assertSame('https://api.exemplo.test/v1/contacts', $chamada['url']);
        $this->assertSame([
            'external_id' => 'usr_9',
            'email' => 'ana@exemplo.com',
            'fields' => ['plano_atual' => 'pro'],
            'lists' => [3],
        ], $chamada['body']);
    }

    /**
     * `lists` ausente precisa CONTINUAR ausente no corpo.
     *
     * Se o pacote completasse com `[]` por conveniência, toda correção de
     * telefone apagaria a segmentação do contato — e ninguém ligaria uma coisa
     * à outra.
     */
    public function test_upsert_sem_lists_nao_inventa_a_chave(): void
    {
        $transporte = (new FakeTransport)->responde(200, ['data' => []]);

        $this->cliente($transporte)->contacts()->upsert([
            'external_id' => 'usr_9',
            'email' => 'ana@exemplo.com',
            'phone' => '+5511999999999',
        ]);

        $this->assertArrayNotHasKey('lists', $transporte->ultima()['body'] ?? []);
    }

    public function test_find_escapa_o_identificador_no_caminho(): void
    {
        $transporte = (new FakeTransport)->responde(200, ['data' => ['external_id' => 'a/b']]);

        $this->cliente($transporte)->contacts()->find('a/b');

        $this->assertSame('https://api.exemplo.test/v1/contacts/a%2Fb', $transporte->ultima()['url']);
    }

    public function test_find_by_email_devolve_o_primeiro_ou_nulo(): void
    {
        $transporte = (new FakeTransport)
            ->responde(200, ['data' => [['email' => 'ana@exemplo.com']]])
            ->responde(200, ['data' => []]);

        $contatos = $this->cliente($transporte)->contacts();

        $this->assertSame('ana@exemplo.com', $contatos->findByEmail('ana@exemplo.com')['email']);
        $this->assertNull($contatos->findByEmail('ninguem@exemplo.com'));
    }

    public function test_exclusao_pelo_email_vai_no_corpo_porque_nem_todo_contato_tem_external_id(): void
    {
        $transporte = (new FakeTransport)->responde(204);

        $this->cliente($transporte)->contacts()->deleteByEmail('ana@exemplo.com');

        $chamada = $transporte->ultima();

        $this->assertSame('DELETE', $chamada['method']);
        $this->assertSame('https://api.exemplo.test/v1/contacts', $chamada['url']);
        $this->assertSame(['email' => 'ana@exemplo.com'], $chamada['body']);
    }

    /**
     * Entrar em lista ACRESCENTA; o `lists` do upsert DEFINE.
     *
     * Se o pacote mandasse o mesmo corpo nos dois, um produto que administra
     * uma lista entre várias apagaria a segmentação feita pelos outros.
     */
    public function test_entrar_em_listas_vai_para_a_sub_rota_e_nao_para_o_upsert(): void
    {
        $transporte = (new FakeTransport)->responde(200, ['data' => []]);

        $this->cliente($transporte)->contacts()->addToLists('usr_9', [3, 7]);

        $chamada = $transporte->ultima();

        $this->assertSame('POST', $chamada['method']);
        $this->assertSame('https://api.exemplo.test/v1/contacts/usr_9/lists', $chamada['url']);
        $this->assertSame(['list_ids' => [3, 7]], $chamada['body']);
    }

    public function test_sair_de_listas_usa_o_mesmo_endereco_com_delete(): void
    {
        $transporte = (new FakeTransport)->responde(200, ['data' => []]);

        $this->cliente($transporte)->contacts()->removeFromLists('usr_9', [3]);

        $chamada = $transporte->ultima();

        $this->assertSame('DELETE', $chamada['method']);
        $this->assertSame('https://api.exemplo.test/v1/contacts/usr_9/lists', $chamada['url']);
        $this->assertSame(['list_ids' => [3]], $chamada['body']);
    }

    /** 204 não tem corpo, e isso não pode virar erro. */
    public function test_exclusao_com_204_nao_quebra(): void
    {
        $transporte = (new FakeTransport)->responde(204);

        $this->cliente($transporte)->contacts()->delete('usr_9');

        $this->assertSame('DELETE', $transporte->ultima()['method']);
    }
}
