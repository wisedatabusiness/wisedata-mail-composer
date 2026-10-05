<?php

declare(strict_types=1);

namespace WiseData\Mail\Tests\Webhook;

use PHPUnit\Framework\TestCase;
use WiseData\Mail\Webhook\WebhookSignature;

/**
 * O par assinatura/verificação, pelo algoritmo da API
 * (`SignedWebhookSender::signature` no back do WiseData Mail).
 */
final class WebhookSignatureTest extends TestCase
{
    private const SEGREDO = 'segredo-de-teste';

    private const CORPO = '{"events":[],"sent_at":"2026-10-05T10:00:00+00:00"}';

    public function test_assina_timestamp_ponto_corpo_em_hmac_sha256(): void
    {
        $esperada = 'sha256='.hash_hmac('sha256', '1700000000.'.self::CORPO, self::SEGREDO);

        $this->assertSame($esperada, WebhookSignature::sign('1700000000', self::CORPO, self::SEGREDO));
    }

    public function test_aceita_a_assinatura_certa_dentro_da_janela(): void
    {
        $assinatura = WebhookSignature::sign('1700000000', self::CORPO, self::SEGREDO);

        $this->assertTrue(WebhookSignature::verify(self::CORPO, '1700000000', $assinatura, self::SEGREDO, now: 1700000300));
    }

    public function test_recusa_fora_da_janela_de_cinco_minutos(): void
    {
        $assinatura = WebhookSignature::sign('1700000000', self::CORPO, self::SEGREDO);

        $this->assertFalse(WebhookSignature::verify(self::CORPO, '1700000000', $assinatura, self::SEGREDO, now: 1700000301));
        $this->assertFalse(WebhookSignature::verify(self::CORPO, '1700000000', $assinatura, self::SEGREDO, now: 1699999699));
    }

    /** Recodificar o JSON muda bytes: o corpo precisa ser o cru. */
    public function test_recusa_corpo_alterado_segredo_errado_e_timestamp_invalido(): void
    {
        $assinatura = WebhookSignature::sign('1700000000', self::CORPO, self::SEGREDO);

        $this->assertFalse(WebhookSignature::verify('{"events": []}', '1700000000', $assinatura, self::SEGREDO, now: 1700000000));
        $this->assertFalse(WebhookSignature::verify(self::CORPO, '1700000000', $assinatura, 'outro', now: 1700000000));
        $this->assertFalse(WebhookSignature::verify(self::CORPO, '', $assinatura, self::SEGREDO, now: 1700000000));
        $this->assertFalse(WebhookSignature::verify(self::CORPO, '1700000000', $assinatura, '', now: 1700000000));
    }
}
