<?php

declare(strict_types=1);

namespace WiseData\Mail\Resource;

use WiseData\Mail\Client;
use WiseData\Mail\Exception\ApiException;

/**
 * Os remetentes do espaço do cliente (ou da conta, sem `forSpace()`).
 *
 * O domínio EXATO do endereço precisa estar cadastrado no mesmo espaço
 * (`domains()->register()`); o remetente fica `verified` quando o domínio
 * fica pronto. Remetente criado pela API nunca vira o padrão do espaço: o
 * envio que quer sair por ele informa o `from`.
 */
final class Senders
{
    public function __construct(private readonly Client $client) {}

    /**
     * Cria ou atualiza nome, resposta e rótulo. Trocar o endereço é excluir e
     * criar outro.
     *
     * @param  string  $label  como o remetente aparece no painel
     * @return array<string, mixed> `from_email`, `from_name`, `reply_to`, `label`, `verified`, `verified_at`
     *
     * @throws ApiException `sender_domain_not_registered` (422) quando o domínio
     *                      do endereço não está cadastrado no espaço
     */
    public function upsert(string $fromEmail, string $fromName, string $label, ?string $replyTo = null): array
    {
        return $this->dado($this->client->request('PUT', 'senders', body: [
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'reply_to' => $replyTo,
            'label' => $label,
        ]));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException `sender_not_found`
     */
    public function find(string $fromEmail): array
    {
        return $this->dado($this->client->request('GET', 'senders/'.rawurlencode($fromEmail)));
    }

    /**
     * @return array<string, mixed> o corpo inteiro, com `data` e `meta`
     *
     * @throws ApiException
     */
    public function list(int $page = 1, int $perPage = 25): array
    {
        return $this->client->request('GET', 'senders', ['page' => $page, 'per_page' => $perPage]);
    }

    /**
     * @throws ApiException `sender_not_found`
     */
    public function delete(string $fromEmail): void
    {
        $this->client->request('DELETE', 'senders/'.rawurlencode($fromEmail));
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
