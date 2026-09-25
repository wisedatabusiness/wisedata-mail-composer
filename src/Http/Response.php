<?php

declare(strict_types=1);

namespace WiseData\Mail\Http;

/**
 * O que voltou da API: o código e o corpo já decodificado.
 */
final class Response
{
    /**
     * @param  array<string, mixed>  $data  corpo decodificado; vazio em 204
     */
    public function __construct(
        public readonly int $status,
        public readonly array $data,
        public readonly string $raw = '',
    ) {}

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Vale a pena tentar de novo?
     *
     * 429 e 5xx, e nada mais. Um 422 repetido devolve 422, e um 401 repetido
     * gasta o balde de limite da chave — repetir aí não é resiliência, é
     * teimosia com custo.
     */
    public function retryable(): bool
    {
        return $this->status === 429 || $this->status >= 500;
    }

    public function get(string $chave, mixed $padrao = null): mixed
    {
        return $this->data[$chave] ?? $padrao;
    }
}
