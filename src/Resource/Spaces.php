<?php

declare(strict_types=1);

namespace WiseData\Mail\Resource;

use WiseData\Mail\Client;
use WiseData\Mail\Exception\ApiException;

/**
 * Os espaços de envio da conta — um por cliente do seu produto.
 *
 * Exige a **chave de plataforma** (escopos `spaces.*`). Chave comum recebe 403
 * `insufficient_scope`.
 *
 * ## Sempre na conta, nunca num espaço
 *
 * Espaço não tem espaço dentro: a API recusa estas rotas com 400
 * `space_not_applicable` quando chega `X-WiseData-Space`. Por isso toda
 * chamada daqui sai pela conta, mesmo de um cliente criado com `forSpace()` —
 * o que permite usar o mesmo objeto para criar o espaço e, em seguida,
 * `forSpace($espaco['slug'])` para cadastrar o domínio dele.
 *
 * ## A identidade é o seu `external_id`
 *
 * O id do cliente no SEU sistema. Chamar `upsert()` de novo com o mesmo
 * `external_id` atualiza em vez de duplicar — inclusive duas chamadas ao mesmo
 * tempo, que terminam num espaço só. Guarde o `slug` da resposta: é o valor do
 * `forSpace()`.
 */
final class Spaces
{
    public function __construct(private readonly Client $client) {}

    /**
     * Cria ou atualiza.
     *
     * `$sendLimit` é o teto de envios do espaço no ciclo; `null` tira o teto.
     * A chamada substitui: omitir o limite numa atualização também o tira.
     *
     * @return array<string, mixed> `external_id`, `slug`, `name`, `send_limit`, `used`, `created_at`
     *
     * @throws ApiException `plan_limit_reached` (o plano não comporta mais espaços),
     *                      `sending_suspended` (a conta está com o envio suspenso)
     */
    public function upsert(string $externalId, string $name, ?int $sendLimit = null): array
    {
        return $this->dado($this->conta()->request('PUT', 'spaces', body: [
            'external_id' => $externalId,
            'name' => $name,
            'send_limit' => $sendLimit,
        ]));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException `space_not_found`
     */
    public function find(string $externalId): array
    {
        return $this->dado($this->conta()->request('GET', 'spaces/'.rawurlencode($externalId)));
    }

    /**
     * @return array<string, mixed> o corpo inteiro, com `data` e `meta`
     *
     * @throws ApiException
     */
    public function list(int $page = 1, int $perPage = 25): array
    {
        return $this->conta()->request('GET', 'spaces', ['page' => $page, 'per_page' => $perPage]);
    }

    /**
     * Exclui o espaço com tudo o que é dele: contatos, domínios, remetentes,
     * histórico. Não tem volta.
     *
     * @throws ApiException `space_not_found`; `space_suspended` (409) quando o
     *                      envio do espaço está suspenso — a suspensão precisa
     *                      ser resolvida antes, para não sumir com a evidência
     */
    public function delete(string $externalId): void
    {
        $this->conta()->request('DELETE', 'spaces/'.rawurlencode($externalId));
    }

    private function conta(): Client
    {
        return $this->client->forSpace(null);
    }

    /**
     * @param  array<string, mixed>  $resposta
     * @return array<string, mixed>
     */
    private function dado(array $resposta): array
    {
        return is_array($resposta['data'] ?? null) ? $resposta['data'] : [];
    }
}
