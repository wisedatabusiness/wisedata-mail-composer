<?php

declare(strict_types=1);

namespace WiseData\Mail\Resource;

use WiseData\Mail\Client;
use WiseData\Mail\Exception\ApiException;

/**
 * Os contatos da conta.
 *
 * ## `lists` ausente mantém, `lists: []` remove
 *
 * A distinção é do contrato da API e vale aqui: um produto que só corrige o
 * telefone não manda `lists`, e a segmentação do contato fica onde estava.
 * Mandar `[]` é o pedido explícito de tirar de todas.
 *
 * É por isso que `upsert()` recebe o array cru em vez de uma lista de
 * parâmetros nomeados: com parâmetro, "não informado" e "vazio" viram o mesmo
 * `null`, e a única forma de os separar seria uma sentinela — que é
 * exatamente o tipo de coisa que quem integra não lê.
 */
final class Contacts
{
    public function __construct(private readonly Client $client) {}

    /**
     * Cria ou corrige. A identidade é `external_id`; sem ele, o e-mail.
     *
     * @param  array<string, mixed>  $contato
     * @return array<string, mixed> o contato gravado
     *
     * @throws ApiException
     */
    public function upsert(array $contato): array
    {
        return $this->dado($this->client->request('PUT', 'contacts', body: $contato));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException `contact_not_found` quando não existe
     */
    public function find(string $externalId): array
    {
        return $this->dado($this->client->request('GET', 'contacts/'.rawurlencode($externalId)));
    }

    /**
     * O contato deste e-mail, ou `null`.
     *
     * Existe porque quem entrou por formulário ou planilha não tem
     * `external_id`, e a listagem filtrada é a única forma de alcançá-lo.
     * Devolve `null` em vez de lançar: "não achei" é resposta normal para uma
     * busca, ao contrário do `find()`, onde o id veio de algum lugar e a
     * ausência é defeito.
     *
     * @return ?array<string, mixed>
     *
     * @throws ApiException
     */
    public function findByEmail(string $email): ?array
    {
        $resposta = $this->client->request('GET', 'contacts', ['email' => $email]);

        $encontrados = is_array($resposta['data'] ?? null) ? $resposta['data'] : [];
        $primeiro = $encontrados[0] ?? null;

        return is_array($primeiro) ? $primeiro : null;
    }

    /**
     * Uma página de contatos.
     *
     * @return array<string, mixed> o corpo inteiro, com `data` e `meta`
     *
     * @throws ApiException
     */
    public function list(int $page = 1, int $perPage = 25): array
    {
        return $this->client->request('GET', 'contacts', ['page' => $page, 'per_page' => $perPage]);
    }

    /**
     * Entrar em listas, sem mexer nas que o contato já tem.
     *
     * Diferente do `lists` do `upsert()`, que DEFINE o conjunto inteiro (e
     * `[]` esvazia). Um produto que administra uma lista entre várias não
     * conhece as outras: com o upsert, teria de ler antes de escrever, e a
     * corrida apagaria o que outro produto vinculou no intervalo.
     *
     * @param  list<int>  $listIds
     * @return array<string, mixed> o contato, já com as listas atualizadas
     *
     * @throws ApiException
     */
    public function addToLists(string $externalId, array $listIds): array
    {
        return $this->dado($this->client->request(
            'POST',
            'contacts/'.rawurlencode($externalId).'/lists',
            body: ['list_ids' => $listIds],
        ));
    }

    /**
     * @param  list<int>  $listIds
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function removeFromLists(string $externalId, array $listIds): array
    {
        return $this->dado($this->client->request(
            'DELETE',
            'contacts/'.rawurlencode($externalId).'/lists',
            body: ['list_ids' => $listIds],
        ));
    }

    /**
     * @throws ApiException
     */
    public function delete(string $externalId): void
    {
        $this->client->request('DELETE', 'contacts/'.rawurlencode($externalId));
    }

    /**
     * @throws ApiException
     */
    public function deleteByEmail(string $email): void
    {
        $this->client->request('DELETE', 'contacts', body: ['email' => $email]);
    }

    /**
     * O `data` da resposta, ou vazio.
     *
     * Vazio e não exceção: um 204 não tem corpo, e quem chamou `delete()` não
     * quer saber disso. Quando o corpo importa, quem falha é a asserção de
     * quem integra, com o dado à vista — não uma exceção aqui, longe da causa.
     *
     * @param  array<string, mixed>  $resposta
     * @return array<string, mixed>
     */
    private function dado(array $resposta): array
    {
        return is_array($resposta['data'] ?? null) ? $resposta['data'] : [];
    }
}
