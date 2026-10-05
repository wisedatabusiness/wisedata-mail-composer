<?php

declare(strict_types=1);

namespace WiseData\Mail\Webhook;

use WiseData\Mail\Exception\InvalidWebhookPayloadException;

/**
 * O corpo inteiro de uma entrega: até cem eventos por `POST`.
 *
 * O ping do botão "Testar" da tela chega com `test: true`, `events: []` e
 * `X-WiseData-Delivery: 0`.
 */
final class WebhookPayload
{
    /**
     * @param  list<WebhookEvent>  $events
     */
    public function __construct(
        public readonly array $events,
        public readonly bool $test = false,
        public readonly ?string $sentAt = null,
    ) {}

    /**
     * Confira a assinatura ANTES: aqui só se lê o formato.
     *
     * @throws InvalidWebhookPayloadException
     */
    public static function parse(string $corpo): self
    {
        $dados = json_decode($corpo, true);

        if (! is_array($dados) || ! is_array($dados['events'] ?? null)) {
            throw new InvalidWebhookPayloadException('O corpo do webhook não é o JSON esperado, com `events`.');
        }

        $eventos = [];

        foreach ($dados['events'] as $item) {
            if (! is_array($item)) {
                throw new InvalidWebhookPayloadException('Item de `events` que não é objeto.');
            }

            /** @var array<string, mixed> $item */
            $eventos[] = WebhookEvent::fromArray($item);
        }

        return new self(
            events: $eventos,
            test: ($dados['test'] ?? false) === true,
            sentAt: is_string($dados['sent_at'] ?? null) ? $dados['sent_at'] : null,
        );
    }
}
