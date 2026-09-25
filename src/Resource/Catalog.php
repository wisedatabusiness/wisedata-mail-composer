<?php

declare(strict_types=1);

namespace WiseData\Mail\Resource;

use WiseData\Mail\Client;
use WiseData\Mail\Exception\ApiException;

/**
 * O que a conta tem para preencher: campos personalizados e listas.
 *
 * Vale a pena ler uma vez no boot do serviço e guardar. A gravação RECUSA campo
 * personalizado desconhecido (`unknown_field`), então é daqui que sai a
 * resposta para "qual é o nome certo".
 */
final class Catalog
{
    public function __construct(private readonly Client $client) {}

    /**
     * Os campos personalizados, cada um com `key`, `label`, `type` e `options`.
     *
     * A `key` é o que a gravação aceita em `fields` — não o `id`.
     *
     * @return list<array<string, mixed>>
     *
     * @throws ApiException
     */
    public function fields(): array
    {
        return $this->lista($this->client->request('GET', 'fields'));
    }

    /**
     * As listas de contato, com `id` e `name`.
     *
     * Aqui é o `id` que a gravação aceita, e não o nome: a pessoa renomeia a
     * lista na tela sem avisar ninguém, e o nome deixaria de casar em silêncio.
     *
     * @return list<array<string, mixed>>
     *
     * @throws ApiException
     */
    public function lists(): array
    {
        return $this->lista($this->client->request('GET', 'lists'));
    }

    /**
     * @param  array<string, mixed>  $resposta
     * @return list<array<string, mixed>>
     */
    private function lista(array $resposta): array
    {
        $dados = $resposta['data'] ?? [];

        if (! is_array($dados)) {
            return [];
        }

        return array_values(array_filter($dados, 'is_array'));
    }
}
