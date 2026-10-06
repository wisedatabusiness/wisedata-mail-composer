<?php

declare(strict_types=1);

namespace WiseData\Mail\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WiseData\Mail\Client;
use WiseData\Mail\Resource\ListReferences;

/**
 * As listas padrão do contato (`WISEDATA_MAIL_CONTACT_LISTS`).
 *
 * O que se prova é o CORPO que sai: o padrão entra como `add_lists` — que
 * acrescenta — e nunca como `lists`, que tiraria a pessoa das listas que o
 * painel ou outro produto lhe deram.
 */
final class ContactListDefaultsTest extends TestCase
{
    /**
     * @param  list<int|string>  $listas
     */
    private function cliente(FakeTransport $transporte, array $listas, ?string $espaco = null): Client
    {
        return new Client(
            token: 'wdm_abc_def',
            baseUrl: 'https://api.exemplo.test',
            space: $espaco,
            retries: 0,
            transport: $transporte,
            contactLists: $listas,
        );
    }

    public function test_sem_falar_de_listas_o_contato_entra_nas_padrao_por_add_lists(): void
    {
        $transporte = (new FakeTransport)->responde(201, ['data' => ['id' => 1]]);

        $this->cliente($transporte, ['clientes-finances'])->contacts()->upsert(['external_id' => 'usr_9', 'email' => 'ana@exemplo.com']);

        $this->assertSame(
            ['external_id' => 'usr_9', 'email' => 'ana@exemplo.com', 'add_lists' => ['clientes-finances']],
            $transporte->ultima()['body'],
        );
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function chamadasQueDecidem(): array
    {
        return [
            'lists explícito' => [['lists' => [7]]],
            'lists vazio (tirar de todas)' => [['lists' => []]],
            'add_lists explícito' => [['add_lists' => ['outra']]],
        ];
    }

    /**
     * @param  array<string, mixed>  $listas
     */
    #[DataProvider('chamadasQueDecidem')]
    public function test_a_chamada_que_fala_de_listas_decide_sozinha(array $listas): void
    {
        $transporte = (new FakeTransport)->responde(200, ['data' => []]);
        $corpo = ['email' => 'ana@exemplo.com', ...$listas];

        $this->cliente($transporte, ['clientes-finances'])->contacts()->upsert($corpo);

        $this->assertSame($corpo, $transporte->ultima()['body']);
    }

    public function test_sem_padrao_o_corpo_segue_como_veio(): void
    {
        $transporte = (new FakeTransport)->responde(200, ['data' => []]);

        $this->cliente($transporte, [])->contacts()->upsert(['email' => 'ana@exemplo.com']);

        $this->assertSame(['email' => 'ana@exemplo.com'], $transporte->ultima()['body']);
    }

    /** Lista é da base de um espaço: em outro, a mesma chave seria outra lista. */
    public function test_trocar_de_espaco_larga_as_listas_padrao(): void
    {
        $transporte = (new FakeTransport)->responde(200, ['data' => []])->responde(200, ['data' => []]);
        $cliente = $this->cliente($transporte, ['clientes-finances'], 'finances');

        $cliente->forSpace('outro')->contacts()->upsert(['email' => 'ana@exemplo.com']);
        $this->assertSame(['email' => 'ana@exemplo.com'], $transporte->ultima()['body']);

        $cliente->forSpace('finances')->contacts()->upsert(['email' => 'ana@exemplo.com']);
        $this->assertSame(['clientes-finances'], $transporte->ultima()['body']['add_lists'] ?? null);
    }

    public function test_entrar_em_lista_pela_chave(): void
    {
        $transporte = (new FakeTransport)->responde(200, ['data' => []]);

        $this->cliente($transporte, [])->contacts()->addToLists('usr_9', ['clientes-finances', 3]);

        $this->assertSame(['list_ids' => ['clientes-finances', 3]], $transporte->ultima()['body']);
    }

    /**
     * @return array<string, array{mixed, list<int|string>}>
     */
    public static function configuracoes(): array
    {
        return [
            'uma chave' => ['clientes-finances', ['clientes-finances']],
            'várias, com espaço e vazio' => [' clientes-finances , ,leads ', ['clientes-finances', 'leads']],
            'número vira id' => ['3,clientes', [3, 'clientes']],
            'repetida sai uma vez' => ['a,a', ['a']],
            'array da config publicada' => [['clientes', 3], ['clientes', 3]],
            'ausente' => [null, []],
            'vazia' => ['', []],
        ];
    }

    /**
     * @param  list<int|string>  $esperado
     */
    #[DataProvider('configuracoes')]
    public function test_le_a_configuracao(mixed $valor, array $esperado): void
    {
        $this->assertSame($esperado, ListReferences::fromConfig($valor));
    }
}
