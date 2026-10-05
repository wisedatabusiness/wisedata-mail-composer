<?php

declare(strict_types=1);

namespace WiseData\Mail\Tests\Webhook;

use PHPUnit\Framework\TestCase;
use WiseData\Mail\Exception\InvalidWebhookPayloadException;
use WiseData\Mail\Webhook\WebhookPayload;

/** O corpo de exemplo é o da documentação da API (`docs/api-publica.md`). */
final class WebhookPayloadTest extends TestCase
{
    public function test_le_o_lote_no_formato_documentado(): void
    {
        $payload = WebhookPayload::parse((string) json_encode([
            'events' => [[
                'id' => 8824, 'type' => 'bounce', 'occurred_at' => '2026-09-25T14:03:20-03:00',
                'email' => 'nao-existe@exemplo.com', 'message_id' => 992, 'campaign_id' => null,
                'message_type' => 'transactional', 'url' => null,
                'reason' => 'smtp; 550 5.1.1 user unknown', 'bounce_classification' => 'invalid_address',
                'machine_open' => false, 'is_test' => false, 'space' => 'marca-b',
            ]],
            'sent_at' => '2026-09-25T14:03:30+00:00',
        ]));

        $this->assertFalse($payload->test);
        $this->assertCount(1, $payload->events);

        $evento = $payload->events[0];
        $this->assertSame(8824, $evento->id);
        $this->assertSame('bounce', $evento->type);
        $this->assertSame(992, $evento->messageId);
        $this->assertTrue($evento->isTransactional());
        $this->assertSame('invalid_address', $evento->bounceClassification);
        $this->assertSame('marca-b', $evento->space);
        $this->assertFalse($evento->isTest);
    }

    public function test_reconhece_o_ping_do_botao_testar(): void
    {
        $payload = WebhookPayload::parse('{"test":true,"events":[],"sent_at":"2026-09-25T14:03:30+00:00"}');

        $this->assertTrue($payload->test);
        $this->assertSame([], $payload->events);
    }

    public function test_recusa_corpo_fora_do_contrato(): void
    {
        $this->expectException(InvalidWebhookPayloadException::class);

        WebhookPayload::parse('{"events":[{"type":"open"}]}');
    }
}
