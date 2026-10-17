<?php

declare(strict_types=1);

namespace WiseData\Mail\Webhook;

/**
 * O domínio de envio no momento em que virou: o `domain` dos eventos
 * `domain.verified`, `domain.failed` e `domain.degraded`.
 *
 * É o mesmo retrato do `GET /v1/domains/{domain}`, sem os registros. Para
 * mostrar ao cliente O QUE publicar, consulte `domains()->find()`.
 */
final class DomainStatus
{
    /**
     * @param  list<string>  $failedRecords  `ownership`, `dkim`, `mail_from`, `tracking`, `dmarc` — os que não estão verdes
     */
    public function __construct(
        public readonly string $domain,
        public readonly bool $readyToSend,
        public readonly ?string $verifiedAt,
        public readonly array $failedRecords,
    ) {}

    public static function fromArray(mixed $dados): ?self
    {
        if (! is_array($dados) || ! is_string($dados['domain'] ?? null)) {
            return null;
        }

        $falhas = is_array($dados['failed_records'] ?? null) ? $dados['failed_records'] : [];

        return new self(
            domain: $dados['domain'],
            readyToSend: ($dados['ready_to_send'] ?? false) === true,
            verifiedAt: is_string($dados['verified_at'] ?? null) ? $dados['verified_at'] : null,
            failedRecords: array_values(array_filter($falhas, is_string(...))),
        );
    }
}
