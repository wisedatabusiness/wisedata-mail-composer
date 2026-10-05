<?php

declare(strict_types=1);

namespace WiseData\Mail\Webhook;

use WiseData\Mail\Exception\InvalidWebhookPayloadException;

/**
 * Um item de `events[]` no corpo do webhook.
 *
 * A API manda todos os campos em todo evento, com `null` nos que não se aplicam.
 * `$raw` guarda o item como chegou, para campo que a API acrescentar antes de o
 * pacote conhecê-lo.
 */
final class WebhookEvent
{
    public const DELIVERED = 'delivered';

    public const BOUNCE = 'bounce';

    public const DROPPED = 'dropped';

    public const SUPPRESSED = 'suppressed';

    public const RENDERING_FAILURE = 'rendering_failure';

    public const SPAM_REPORT = 'spamreport';

    public const OPEN = 'open';

    public const CLICK = 'click';

    public const UNSUBSCRIBE = 'unsubscribe';

    public const RESUBSCRIBE = 'resubscribe';

    /**
     * @param  int  $messageId  o `id` devolvido no envio — é o que `SentMessage::getMessageId()` guarda
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $occurredAt,
        public readonly string $email,
        public readonly ?int $messageId,
        public readonly ?int $campaignId,
        public readonly ?string $messageType,
        public readonly ?string $url,
        public readonly ?string $reason,
        public readonly ?string $bounceClassification,
        public readonly bool $machineOpen,
        public readonly bool $isTest,
        public readonly ?string $space,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $dados
     *
     * @throws InvalidWebhookPayloadException
     */
    public static function fromArray(array $dados): self
    {
        $id = $dados['id'] ?? null;
        $tipo = $dados['type'] ?? null;

        if (! is_int($id) || ! is_string($tipo) || $tipo === '') {
            throw new InvalidWebhookPayloadException('Evento de webhook sem `id` inteiro ou sem `type`.');
        }

        return new self(
            id: $id,
            type: $tipo,
            occurredAt: self::texto($dados['occurred_at'] ?? null) ?? '',
            email: self::texto($dados['email'] ?? null) ?? '',
            messageId: self::inteiro($dados['message_id'] ?? null),
            campaignId: self::inteiro($dados['campaign_id'] ?? null),
            messageType: self::texto($dados['message_type'] ?? null),
            url: self::texto($dados['url'] ?? null),
            reason: self::texto($dados['reason'] ?? null),
            bounceClassification: self::texto($dados['bounce_classification'] ?? null),
            machineOpen: ($dados['machine_open'] ?? false) === true,
            isTest: ($dados['is_test'] ?? false) === true,
            space: self::texto($dados['space'] ?? null),
            raw: $dados,
        );
    }

    public function isTransactional(): bool
    {
        return $this->campaignId === null;
    }

    private static function texto(mixed $valor): ?string
    {
        return is_string($valor) && $valor !== '' ? $valor : null;
    }

    private static function inteiro(mixed $valor): ?int
    {
        return is_int($valor) ? $valor : null;
    }
}
