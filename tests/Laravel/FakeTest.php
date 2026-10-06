<?php

declare(strict_types=1);

namespace WiseData\Mail\Tests\Laravel;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\AssertionFailedError;
use WiseData\Mail\Client;
use WiseData\Mail\Contracts\Transport;
use WiseData\Mail\Http\CurlTransport;
use WiseData\Mail\Laravel\Facades\WiseDataMail;
use WiseData\Mail\Mailer\WiseDataMailTransport;
use WiseData\Mail\Mailer\WiseDataMailTransportException;
use WiseData\Mail\Testing\WiseDataMailFake;

/**
 * A fake troca só a fronteira HTTP: o transport do mailer e o `Client` que
 * rodam são os reais, então o teste da aplicação prova o corpo que sairia.
 */
final class FakeTest extends TestCase
{
    public function test_sem_fake_o_transporte_padrao_e_o_curl(): void
    {
        $this->assertInstanceOf(CurlTransport::class, $this->app->make(Transport::class));
        $this->assertInstanceOf(WiseDataMailTransport::class, Mail::mailer('wisedatamail')->getSymfonyTransport());
    }

    /** Mailer já resolvido antes do fake guardaria o cURL — o fake o descarta. */
    public function test_o_mailer_ja_resolvido_passa_a_usar_a_fake(): void
    {
        Mail::mailer('wisedatamail');

        $fake = WiseDataMail::fake();

        $enviada = Mail::mailer('wisedatamail')->raw('Corpo em texto', static fn (Message $m) => $m
            ->to('ana@exemplo.com', 'Ana')
            ->subject('Assunto'));

        $fake->assertSentCount(1);
        $fake->assertSentTo('ana@exemplo.com', static fn (array $corpo): bool => $corpo['subject'] === 'Assunto'
            && $corpo['text'] === 'Corpo em texto'
            && $corpo['from'] === 'avisos@exemplo.com');
        $fake->assertNotSentTo('bia@exemplo.com');

        $this->assertSame('1', $enviada?->getMessageId());
        $this->assertSame('Bearer wdm_teste_token', $fake->requests()[0]['headers']['Authorization']);
    }

    public function test_o_client_do_conteiner_tambem_usa_a_fake(): void
    {
        app(Client::class);

        $fake = WiseDataMail::fake();

        WiseDataMail::emails()->send(['to' => 'ana@exemplo.com', 'subject' => 'a', 'html' => '<p>a</p>']);
        WiseDataMail::contacts()->upsert(['email' => 'ana@exemplo.com']);

        $fake->assertSentCount(1);
        $this->assertCount(2, $fake->requests());
    }

    /** A resposta da fake tem a forma da API: o teste da aplicação que lê `subject` não pode ver `null` só no CI. */
    public function test_a_resposta_da_fake_devolve_o_assunto_como_a_api(): void
    {
        WiseDataMail::fake();

        $resposta = WiseDataMail::emails()->send(['to' => 'ana@exemplo.com', 'subject' => 'Sua fatura', 'html' => '<p>a</p>']);

        $this->assertSame('Sua fatura', $resposta['subject'] ?? null);
    }

    public function test_assert_nothing_sent_falha_quando_houve_envio(): void
    {
        $fake = WiseDataMail::fake();
        $fake->assertNothingSent();

        Mail::mailer('wisedatamail')->raw('x', static fn (Message $m) => $m->to('ana@exemplo.com')->subject('x'));

        $this->expectException(AssertionFailedError::class);
        $fake->assertNothingSent();
    }

    public function test_fail_with_recusa_como_a_api_e_nao_conta_como_enviado(): void
    {
        $fake = WiseDataMail::fake()->failWith('send_quota_exceeded', 403);

        try {
            Mail::mailer('wisedatamail')->raw('x', static fn (Message $m) => $m->to('ana@exemplo.com')->subject('x'));
            $this->fail('a recusa precisa chegar à aplicação');
        } catch (WiseDataMailTransportException $e) {
            $this->assertSame('send_quota_exceeded', $e->error);
        }

        $fake->assertNothingSent();
        $this->assertCount(1, $fake->requests());
    }

    public function test_sem_token_configurado_a_fake_ainda_funciona(): void
    {
        config(['wisedata-mail.token' => null]);

        $fake = WiseDataMail::fake();

        Mail::mailer('wisedatamail')->raw('x', static fn (Message $m) => $m->to('ana@exemplo.com')->subject('x'));

        $this->assertInstanceOf(WiseDataMailFake::class, $fake);
        $fake->assertSentTo('ana@exemplo.com');
    }

    public function test_remetente_da_conta_por_configuracao(): void
    {
        config(['wisedata-mail.mail.use_account_sender' => true]);

        $fake = WiseDataMail::fake();

        Mail::mailer('wisedatamail')->raw('x', static fn (Message $m) => $m->to('ana@exemplo.com')->subject('x'));

        $fake->assertSent(static fn (array $corpo): bool => ! array_key_exists('from', $corpo));
    }

    /** O ponto de injeção: a aplicação troca o transporte com um `bind`. */
    public function test_a_aplicacao_troca_o_transporte_pelo_conteiner(): void
    {
        $meu = new WiseDataMailFake;
        $this->app->instance(Transport::class, $meu);
        $this->app->forgetInstance(Client::class);

        app(Client::class)->emails()->send(['to' => 'ana@exemplo.com', 'subject' => 'a', 'html' => 'a']);

        $meu->assertSentTo('ana@exemplo.com');
    }
}
