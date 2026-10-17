<?php

declare(strict_types=1);

namespace WiseData\Mail\Resource;

use WiseData\Mail\Client;
use WiseData\Mail\Exception\ApiException;

/**
 * Os domínios de envio do espaço do cliente (ou da conta, sem `forSpace()`).
 *
 * O fluxo: `register()` devolve os registros DNS que o cliente publica; o Mail
 * confere sozinho de tempos em tempos e avisa pelo webhook (`domain.verified`)
 * quando o domínio fica pronto. Não é preciso consultar em laço.
 *
 * O domínio só fica pronto com **DMARC** publicado — no próprio domínio ou no
 * domínio organizacional, quando o envio é de um subdomínio.
 */
final class Domains
{
    public function __construct(private readonly Client $client) {}

    /**
     * Cadastra o domínio. Já cadastrado neste espaço: devolve o que existe, sem
     * efeito — dá para chamar de novo sem medo.
     *
     * @return array<string, mixed> `domain`, `ready_to_send`, `verified_at`,
     *                              `last_checked_at`, `checking`, `failed_records`
     *                              e `records` (os registros a publicar)
     *
     * @throws ApiException `plan_limit_reached`; `provider_unavailable` (503,
     *                      com `retryAfter()`) quando o provedor de envio pede
     *                      para esperar
     */
    public function register(string $domain): array
    {
        return $this->dado($this->client->request('PUT', 'domains', body: ['domain' => $domain]));
    }

    /**
     * O estado de agora, com os registros e o status de cada um.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException `domain_not_found`
     */
    public function find(string $domain): array
    {
        return $this->dado($this->client->request('GET', 'domains/'.rawurlencode($domain)));
    }

    /**
     * @return array<string, mixed> o corpo inteiro, com `data` e `meta`
     *
     * @throws ApiException
     */
    public function list(int $page = 1, int $perPage = 25): array
    {
        return $this->client->request('GET', 'domains', ['page' => $page, 'per_page' => $perPage]);
    }

    /**
     * Confere agora, e retoma a conferência automática se ela tinha desistido
     * (`checking: false` sem estar pronto, ou depois de `domain.failed`).
     *
     * Para o botão "já publiquei" da sua tela. Uma vez por minuto por domínio.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException `domain_not_found`; `rate_limited` (429, com
     *                      `retryAfter()`) quando chamado antes do minuto
     */
    public function verify(string $domain): array
    {
        return $this->dado($this->client->request('POST', 'domains/'.rawurlencode($domain).'/verify'));
    }

    /**
     * @throws ApiException `domain_not_found`
     */
    public function delete(string $domain): void
    {
        $this->client->request('DELETE', 'domains/'.rawurlencode($domain));
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
