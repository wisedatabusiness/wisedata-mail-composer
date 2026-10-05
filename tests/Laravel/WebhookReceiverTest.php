<?php

declare(strict_types=1);

namespace WiseData\Mail\Tests\Laravel;

use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use WiseData\Mail\Laravel\Events\WiseDataMailBounced;
use WiseData\Mail\Laravel\Events\WiseDataMailDelivered;
use WiseData\Mail\Laravel\Events\WiseDataMailUnknownEvent;
use WiseData\Mail\Webhook\WebhookSignature;

final class WebhookReceiverTest extends TestCase
{
    private const SEGREDO = 'segredo-de-teste';

    /** Só os eventos do pacote: `Event::fake()` sem lista também pega os do framework. */
    private const EVENTOS = [
        WiseDataMailDelivered::class,
        WiseDataMailBounced::class,
        WiseDataMailUnknownEvent::class,
    ];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('wisedata-mail.webhook.secret', self::SEGREDO);
        $app['config']->set('wisedata-mail.webhook.path', '/webhooks/wisedata-mail');
    }

    public function test_dispara_um_evento_por_tipo(): void
    {
        Event::fake(self::EVENTOS);

        $this->entregar([$this->evento(1, 'delivered'), $this->evento(2, 'bounce'), $this->evento(3, 'tipo_novo')])
            ->assertNoContent();

        Event::assertDispatched(WiseDataMailDelivered::class, static fn (WiseDataMailDelivered $e): bool => $e->event->id === 1
            && $e->deliveryId === '77');
        Event::assertDispatched(WiseDataMailBounced::class, static fn (WiseDataMailBounced $e): bool => $e->event->bounceClassification === 'invalid_address');
        Event::assertDispatched(WiseDataMailUnknownEvent::class);
    }

    /** A API reenvia o mesmo lote com o mesmo `X-WiseData-Delivery`. */
    public function test_lote_repetido_nao_dispara_de_novo(): void
    {
        Event::fake(self::EVENTOS);

        $this->entregar([$this->evento(1, 'delivered')])->assertNoContent();
        $this->entregar([$this->evento(1, 'delivered')])->assertNoContent();

        Event::assertDispatchedTimes(WiseDataMailDelivered::class, 1);
    }

    /** Listener que falha devolve erro e libera o lote para o reenvio processar. */
    public function test_listener_que_falha_libera_o_reenvio(): void
    {
        $tentativas = 0;

        Event::listen(WiseDataMailDelivered::class, static function () use (&$tentativas): void {
            $tentativas++;

            if ($tentativas === 1) {
                throw new RuntimeException('banco fora do ar');
            }
        });

        $this->withoutExceptionHandling();

        try {
            $this->entregar([$this->evento(1, 'delivered')]);
            $this->fail('a falha do listener precisa chegar à API como erro');
        } catch (RuntimeException) {
        }

        $this->entregar([$this->evento(1, 'delivered')])->assertNoContent();

        $this->assertSame(2, $tentativas);
    }

    public function test_assinatura_errada_e_recusada(): void
    {
        Event::fake(self::EVENTOS);

        $corpo = $this->corpo([$this->evento(1, 'delivered')]);

        $this->call('POST', '/webhooks/wisedata-mail', server: $this->cabecalhos($corpo, assinatura: 'sha256=errada'), content: $corpo)
            ->assertUnauthorized();

        Event::assertNothingDispatched();
    }

    public function test_assinatura_fora_da_janela_e_recusada(): void
    {
        Event::fake(self::EVENTOS);

        $corpo = $this->corpo([$this->evento(1, 'delivered')]);

        $this->call('POST', '/webhooks/wisedata-mail', server: $this->cabecalhos($corpo, timestamp: time() - 301), content: $corpo)
            ->assertUnauthorized();

        Event::assertNothingDispatched();
    }

    public function test_sem_segredo_configurado_recusa_com_500(): void
    {
        config(['wisedata-mail.webhook.secret' => null]);
        Event::fake(self::EVENTOS);

        $this->entregar([$this->evento(1, 'delivered')])->assertStatus(500);

        Event::assertNothingDispatched();
    }

    public function test_ping_do_botao_testar_responde_sem_evento(): void
    {
        Event::fake(self::EVENTOS);

        $corpo = '{"test":true,"events":[],"sent_at":"2026-10-05T10:00:00+00:00"}';

        $this->call('POST', '/webhooks/wisedata-mail', server: $this->cabecalhos($corpo, entrega: '0'), content: $corpo)
            ->assertNoContent();

        Event::assertNothingDispatched();
    }

    /** O desfecho simulado chega ao mesmo endereço dos reais. */
    public function test_evento_simulado_e_descartado_por_padrao(): void
    {
        Event::fake(self::EVENTOS);

        $this->entregar([$this->evento(1, 'bounce', isTest: true)])->assertNoContent();

        Event::assertNotDispatched(WiseDataMailBounced::class);
    }

    public function test_evento_simulado_passa_quando_a_homologacao_pede(): void
    {
        config(['wisedata-mail.webhook.accept_test_events' => true]);
        Event::fake(self::EVENTOS);

        $this->entregar([$this->evento(1, 'bounce', isTest: true)])->assertNoContent();

        Event::assertDispatched(WiseDataMailBounced::class, static fn (WiseDataMailBounced $e): bool => $e->event->isTest);
    }

    public function test_corpo_fora_do_contrato_e_400(): void
    {
        $corpo = '{"nada":true}';

        $this->call('POST', '/webhooks/wisedata-mail', server: $this->cabecalhos($corpo), content: $corpo)
            ->assertStatus(400);
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param  list<array<string, mixed>>  $eventos
     */
    private function entregar(array $eventos, string $entrega = '77'): TestResponse
    {
        $corpo = $this->corpo($eventos);

        return $this->call('POST', '/webhooks/wisedata-mail', server: $this->cabecalhos($corpo, entrega: $entrega), content: $corpo);
    }

    /**
     * @param  list<array<string, mixed>>  $eventos
     */
    private function corpo(array $eventos): string
    {
        return (string) json_encode(['events' => $eventos, 'sent_at' => '2026-10-05T10:00:00+00:00']);
    }

    /**
     * @return array<string, string>
     */
    private function cabecalhos(string $corpo, string $entrega = '77', ?int $timestamp = null, ?string $assinatura = null): array
    {
        $timestamp = (string) ($timestamp ?? time());

        return [
            'HTTP_X_WISEDATA_TIMESTAMP' => $timestamp,
            'HTTP_X_WISEDATA_SIGNATURE' => $assinatura ?? WebhookSignature::sign($timestamp, $corpo, self::SEGREDO),
            'HTTP_X_WISEDATA_DELIVERY' => $entrega,
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function evento(int $id, string $tipo, bool $isTest = false): array
    {
        return [
            'id' => $id, 'type' => $tipo, 'occurred_at' => '2026-10-05T10:00:00-03:00',
            'email' => 'ana@exemplo.com', 'message_id' => 991, 'campaign_id' => null,
            'message_type' => 'transactional', 'url' => null,
            'reason' => $tipo === 'bounce' ? 'smtp; 550 5.1.1 user unknown' : null,
            'bounce_classification' => $tipo === 'bounce' ? 'invalid_address' : null,
            'machine_open' => false, 'is_test' => $isTest, 'space' => null,
        ];
    }
}
