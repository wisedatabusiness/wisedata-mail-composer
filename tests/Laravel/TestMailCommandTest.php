<?php

declare(strict_types=1);

namespace WiseData\Mail\Tests\Laravel;

use WiseData\Mail\Laravel\Facades\WiseDataMail;

final class TestMailCommandTest extends TestCase
{
    public function test_envia_e_mostra_o_id(): void
    {
        $fake = WiseDataMail::fake();

        $this->artisan('wisedata-mail:test', ['email' => 'ana@exemplo.com'])
            ->expectsOutputToContain('Remetente: avisos@exemplo.com')
            ->expectsOutputToContain('Aceito pelo WiseData Mail.')
            ->expectsOutputToContain('Id da mensagem: 1')
            ->assertSuccessful();

        $fake->assertSentTo('ana@exemplo.com');
    }

    public function test_mostra_o_codigo_do_erro_da_api(): void
    {
        WiseDataMail::fake()->failWith('invalid_api_key', 401, 'Chave inválida.');

        $this->artisan('wisedata-mail:test', ['email' => 'ana@exemplo.com'])
            ->expectsOutputToContain('invalid_api_key')
            ->assertFailed();
    }

    /** O 422 mais provável: `MAIL_FROM_ADDRESS` que não é remetente verificado. */
    public function test_mostra_o_campo_recusado_na_validacao(): void
    {
        WiseDataMail::fake()->failWith('validation_failed', 422, 'Remetente não verificado.', [
            'errors' => ['from' => ['Remetente não verificado.']],
        ]);

        $this->artisan('wisedata-mail:test', ['email' => 'ana@exemplo.com'])
            ->expectsOutputToContain('validation_failed')
            ->expectsOutputToContain('from: Remetente não verificado.')
            ->assertFailed();
    }

    public function test_sem_token_falha_sem_enviar(): void
    {
        config(['wisedata-mail.token' => null]);

        $this->artisan('wisedata-mail:test', ['email' => 'ana@exemplo.com'])
            ->expectsOutputToContain('token da API está vazio')
            ->assertFailed();
    }

    public function test_email_invalido_nao_chama_a_api(): void
    {
        $fake = WiseDataMail::fake();

        $this->artisan('wisedata-mail:test', ['email' => 'nao-e-email'])
            ->expectsOutputToContain('E-mail inválido')
            ->assertFailed();

        $this->assertSame([], $fake->requests());
    }

    public function test_recusa_mailer_que_nao_e_o_wisedatamail(): void
    {
        $fake = WiseDataMail::fake();

        $this->artisan('wisedata-mail:test', ['email' => 'ana@exemplo.com', '--mailer' => 'array'])
            ->expectsOutputToContain('não usa o transport wisedatamail')
            ->assertFailed();

        $fake->assertNothingSent();
    }
}
