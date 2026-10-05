<?php

declare(strict_types=1);

namespace WiseData\Mail\Webhook;

/**
 * A assinatura dos webhooks do WiseData Mail, sem framework.
 *
 * `sha256=` + HMAC-SHA256 de `timestamp.corpo_cru`, com o segredo exibido na
 * criação do webhook. O timestamp entra no material assinado e a janela recusa o
 * que foi assinado há muito tempo: sem os dois, um par (corpo, assinatura)
 * capturado seria aceito para sempre.
 */
final class WebhookSignature
{
    public const HEADER_SIGNATURE = 'X-WiseData-Signature';

    public const HEADER_TIMESTAMP = 'X-WiseData-Timestamp';

    public const HEADER_DELIVERY = 'X-WiseData-Delivery';

    public const HEADER_EVENT_COUNT = 'X-WiseData-Event-Count';

    /** Cinco minutos, a janela que a documentação da API recomenda. */
    public const DEFAULT_TOLERANCE = 300;

    public static function sign(string $timestamp, string $payload, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }

    /**
     * @param  string  $payload  o corpo CRU — decodificar e recodificar o JSON muda bytes e a conferência falha
     * @param  ?int  $now  injetável só para teste
     */
    public static function verify(
        string $payload,
        string $timestamp,
        string $signature,
        string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): bool {
        if ($secret === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(($now ?? time()) - (int) $timestamp) > $tolerance) {
            return false;
        }

        return hash_equals(self::sign($timestamp, $payload, $secret), $signature);
    }
}
