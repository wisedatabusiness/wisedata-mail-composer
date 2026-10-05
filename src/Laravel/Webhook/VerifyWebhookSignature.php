<?php

declare(strict_types=1);

namespace WiseData\Mail\Laravel\Webhook;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use WiseData\Mail\Webhook\WebhookSignature;

/**
 * Recusa o que não foi assinado pelo WiseData Mail, ou foi assinado fora da janela.
 *
 * Lê `$request->getContent()`: assinar o resultado de `$request->all()` nunca
 * bate, porque a recodificação do JSON muda bytes.
 */
final class VerifyWebhookSignature
{
    public function __construct(private readonly Repository $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        $segredo = $this->config->get('wisedata-mail.webhook.secret');

        if (! is_string($segredo) || $segredo === '') {
            /*
             * 500 e não 401: o defeito é a configuração daqui, e a API reenvia o
             * lote — que é processado quando o segredo for preenchido.
             */
            return new Response('WISEDATA_MAIL_WEBHOOK_SECRET não está configurado.', 500);
        }

        $tolerancia = $this->config->get('wisedata-mail.webhook.tolerance', WebhookSignature::DEFAULT_TOLERANCE);

        $valida = WebhookSignature::verify(
            $request->getContent(),
            (string) $request->headers->get(WebhookSignature::HEADER_TIMESTAMP),
            (string) $request->headers->get(WebhookSignature::HEADER_SIGNATURE),
            $segredo,
            is_numeric($tolerancia) ? (int) $tolerancia : WebhookSignature::DEFAULT_TOLERANCE,
        );

        if (! $valida) {
            return new Response('', 401);
        }

        return $next($request);
    }
}
