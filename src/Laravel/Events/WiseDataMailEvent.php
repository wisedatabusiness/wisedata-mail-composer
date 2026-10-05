<?php

declare(strict_types=1);

namespace WiseData\Mail\Laravel\Events;

use WiseData\Mail\Webhook\WebhookEvent;

/**
 * Base dos eventos Laravel disparados pelo receptor de webhook.
 *
 * O listener escuta a classe concreta (`WiseDataMailBounced`, ...): o dispatcher
 * do Laravel não entrega a quem escuta a classe-mãe.
 *
 * O mesmo lote pode chegar de novo se um listener lançar exceção (o receptor
 * devolve erro e a API reenvia), então o listener deve ser idempotente por
 * `$event->event->id`.
 */
abstract class WiseDataMailEvent
{
    final public function __construct(
        public readonly WebhookEvent $event,
        public readonly string $deliveryId,
    ) {}

    public static function from(WebhookEvent $evento, string $deliveryId): self
    {
        $classe = match ($evento->type) {
            WebhookEvent::DELIVERED => WiseDataMailDelivered::class,
            WebhookEvent::BOUNCE => WiseDataMailBounced::class,
            WebhookEvent::DROPPED => WiseDataMailDropped::class,
            WebhookEvent::SUPPRESSED => WiseDataMailSuppressed::class,
            WebhookEvent::RENDERING_FAILURE => WiseDataMailRenderingFailed::class,
            WebhookEvent::SPAM_REPORT => WiseDataMailSpamReported::class,
            WebhookEvent::OPEN => WiseDataMailOpened::class,
            WebhookEvent::CLICK => WiseDataMailClicked::class,
            WebhookEvent::UNSUBSCRIBE => WiseDataMailUnsubscribed::class,
            WebhookEvent::RESUBSCRIBE => WiseDataMailResubscribed::class,
            default => WiseDataMailUnknownEvent::class,
        };

        return new $classe($evento, $deliveryId);
    }
}
