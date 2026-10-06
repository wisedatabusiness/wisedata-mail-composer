<?php

declare(strict_types=1);

namespace WiseData\Mail\Tests\Laravel;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Mail;
use WiseData\Mail\Exception\ApiException;
use WiseData\Mail\Laravel\Facades\WiseDataMail;
use WiseData\Mail\Mailer\WiseDataMailTransportException;

/**
 * O incidente que motivou a 1.2.0, reproduzido com um `Mailable` de verdade.
 *
 * O `Envelope` do Laravel vira `TagHeader`/`MetadataHeader` no Symfony. A 1.1.x
 * mandava os dois como cabeçalho `X-`: `X-Metadata-user_id` voltava 422 da API
 * (o `_` não é aceito em nome de cabeçalho) e, se passasse, o `user_id` iria
 * visível ao destinatário.
 */
final class EnvelopeTagsTest extends TestCase
{
    public function test_tags_e_metadados_do_envelope_vao_no_corpo_e_nunca_em_headers(): void
    {
        $fake = WiseDataMail::fake();

        Mail::mailer('wisedatamail')->to('ana@exemplo.com')->send($this->lembrete(
            tags: ['due-reminders', 'digest'],
            metadata: ['user_id' => 42, 'reminder_type' => 'digest'],
        ));

        $fake->assertSentCount(1);
        $corpo = $fake->sent()[0];

        $this->assertSame(['due-reminders', 'digest'], $corpo['tags'] ?? null);
        $this->assertSame(['user_id' => '42', 'reminder_type' => 'digest'], $corpo['metadata'] ?? null);
        $this->assertArrayNotHasKey('headers', $corpo, 'tag ou metadado vazou como cabeçalho do e-mail.');
    }

    /** O `X-` válido da aplicação continua indo junto, como na 1.1.x. */
    public function test_cabecalho_x_valido_continua_indo_ao_lado_dos_metadados(): void
    {
        $fake = WiseDataMail::fake();

        Mail::mailer('wisedatamail')->to('ana@exemplo.com')->send(
            $this->lembrete(metadata: ['user_id' => 1])->withSymfonyMessage(
                static fn ($m) => $m->getHeaders()->addTextHeader('X-Pedido', '1234'),
            ),
        );

        $corpo = $fake->sent()[0];

        $this->assertSame(['X-Pedido' => '1234'], $corpo['headers'] ?? null);
        $this->assertSame(['user_id' => '1'], $corpo['metadata'] ?? null);
    }

    /** Chave fora da regra da API quebra no teste da aplicação, não em produção. */
    public function test_metadado_com_chave_invalida_e_recusado_antes_da_rede(): void
    {
        $fake = WiseDataMail::fake();

        try {
            /* Começar por dígito é válido para o Symfony e inválido para a API. */
            Mail::mailer('wisedatamail')->to('ana@exemplo.com')->send($this->lembrete(metadata: ['2fa_user' => 1]));
            $this->fail('a chave começando por dígito precisa ser recusada.');
        } catch (WiseDataMailTransportException $e) {
            $this->assertSame('validation_failed', $e->error);
            $this->assertStringContainsString('metadata_keys.0', $e->getMessage());
        }

        $this->assertSame([], $fake->requests(), 'nenhuma requisição podia ter saído.');
    }

    /**
     * A fake aplica as regras da API mesmo a quem chama o `Client` direto,
     * sem passar pelo transport.
     */
    public function test_a_fake_recusa_como_a_api(): void
    {
        $fake = WiseDataMail::fake();

        try {
            WiseDataMail::emails()->send([
                'to' => 'ana@exemplo.com', 'subject' => 'a', 'html' => '<p>a</p>',
                'headers' => ['X-Metadata-user_id' => '1'],
                'tags' => array_map(static fn (int $i): string => "t{$i}", range(1, 11)),
            ]);
            $this->fail('a fake aceitou o que a API recusa.');
        } catch (ApiException $e) {
            $this->assertSame('validation_failed', $e->error);
            $this->assertSame(['header_names.0', 'tags'], array_keys((array) ($e->extra['errors'] ?? [])));
        }

        $fake->assertNothingSent();
    }

    /** Contraprova: no teto, a fake aceita. */
    public function test_a_fake_aceita_no_limite(): void
    {
        $fake = WiseDataMail::fake();

        $metadados = [];

        foreach (range(1, 10) as $i) {
            $metadados[str_pad("k{$i}_", 40, 'a')] = str_repeat('v', 255);
        }

        WiseDataMail::emails()->send([
            'to' => 'ana@exemplo.com', 'subject' => 'a', 'html' => '<p>a</p>',
            'headers' => ['X-Pedido-Id' => '1'],
            'tags' => array_map(static fn (int $i): string => str_pad("t{$i}", 128, 'x'), range(1, 10)),
            'metadata' => $metadados,
        ]);

        $fake->assertSentCount(1);
    }

    /**
     * @param  list<string>  $tags
     * @param  array<string, mixed>  $metadata
     */
    private function lembrete(array $tags = [], array $metadata = []): Mailable
    {
        return new class($tags, $metadata) extends Mailable
        {
            /**
             * @param  list<string>  $etiquetas
             * @param  array<string, mixed>  $metadados
             */
            public function __construct(private array $etiquetas, private array $metadados) {}

            public function envelope(): Envelope
            {
                return new Envelope(subject: 'Suas contas vencem amanhã', tags: $this->etiquetas, metadata: $this->metadados);
            }

            public function content(): Content
            {
                return new Content(htmlString: '<p>Vencem amanhã.</p>');
            }
        };
    }
}
