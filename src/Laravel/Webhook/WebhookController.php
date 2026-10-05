<?php

declare(strict_types=1);

namespace WiseData\Mail\Laravel\Webhook;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use WiseData\Mail\Exception\InvalidWebhookPayloadException;
use WiseData\Mail\Laravel\Events\WiseDataMailEvent;
use WiseData\Mail\Webhook\WebhookPayload;
use WiseData\Mail\Webhook\WebhookSignature;

/**
 * Recebe o lote já com a assinatura conferida (`VerifyWebhookSignature`) e
 * dispara um evento Laravel por item.
 *
 * A API espera 2xx em até dez segundos: listener demorado deve implementar
 * `ShouldQueue`, para o trabalho sair daqui e ir para a fila.
 */
final class WebhookController
{
    private const PREFIXO_CACHE = 'wisedata-mail:webhook:delivery:';

    public function __construct(
        private readonly Dispatcher $events,
        private readonly CacheFactory $cache,
        private readonly Repository $config,
    ) {}

    public function __invoke(Request $request): Response
    {
        try {
            $payload = WebhookPayload::parse($request->getContent());
        } catch (InvalidWebhookPayloadException) {
            return new Response('', 400);
        }

        $entrega = (string) $request->headers->get(WebhookSignature::HEADER_DELIVERY);

        /* O ping do botão "Testar": entrega `0` e nenhum evento. */
        if ($payload->test || $entrega === '0') {
            return new Response('', 204);
        }

        $loja = $this->cache->store($this->lojaDeCache());
        $chave = self::PREFIXO_CACHE.$entrega;

        /*
         * `add` é atômico: dois reenvios simultâneos do mesmo lote não passam os
         * dois. A marca vem ANTES dos listeners para cobrir essa corrida, e sai se
         * algum deles falhar — senão o reenvio seria descartado e o lote, perdido.
         */
        if ($entrega !== '' && ! $loja->add($chave, true, $this->ttl())) {
            return new Response('', 204);
        }

        try {
            foreach ($payload->events as $evento) {
                if ($evento->isTest && ! $this->aceitaTeste()) {
                    continue;
                }

                $this->events->dispatch(WiseDataMailEvent::from($evento, $entrega));
            }
        } catch (Throwable $e) {
            if ($entrega !== '') {
                $loja->forget($chave);
            }

            throw $e;
        }

        return new Response('', 204);
    }

    private function lojaDeCache(): ?string
    {
        $loja = $this->config->get('wisedata-mail.webhook.cache_store');

        return is_string($loja) && $loja !== '' ? $loja : null;
    }

    private function ttl(): int
    {
        $ttl = $this->config->get('wisedata-mail.webhook.dedupe_ttl', 86400);

        return is_numeric($ttl) && (int) $ttl > 0 ? (int) $ttl : 86400;
    }

    private function aceitaTeste(): bool
    {
        return filter_var($this->config->get('wisedata-mail.webhook.accept_test_events', false), FILTER_VALIDATE_BOOLEAN);
    }
}
