<?php

declare(strict_types=1);

namespace WiseData\Mail\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use WiseData\Mail\Client;
use WiseData\Mail\Mailer\WiseDataMailTransport;

/**
 * Registra o `Client` no contêiner do Laravel.
 *
 * Descoberto automaticamente pelo `extra.laravel.providers` do `composer.json`:
 * instalar o pacote e preencher `WISEDATA_MAIL_TOKEN` é tudo — não há passo de
 * registro manual, e é isto que faz a instalação caber numa linha em vinte
 * produtos.
 *
 * ```php
 * app(\WiseData\Mail\Client::class)->contacts()->upsert([...]);
 * ```
 */
final class WiseDataMailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * `mergeConfigFrom` para o pacote funcionar SEM publicar a config: o
         * caso comum é só preencher o `.env`, e obrigar um `vendor:publish`
         * antes da primeira chamada é um passo que ninguém lembra.
         */
        $this->mergeConfigFrom(__DIR__.'/../../config/wisedata-mail.php', 'wisedata-mail');

        /*
         * `singleton`: o cliente não guarda estado de requisição — só token,
         * URL e espaço —, então criar um por injeção seria desperdício. Quem
         * precisa de outro espaço usa `forSpace()`, que devolve um clone.
         */
        $this->app->singleton(Client::class, static function (Application $app): Client {
            /** @var \Illuminate\Contracts\Config\Repository $config */
            $config = $app->make('config');

            return new Client(
                token: (string) $config->get('wisedata-mail.token', ''),
                baseUrl: (string) $config->get('wisedata-mail.base_url', 'https://api.wisedatamail.com'),
                space: $config->get('wisedata-mail.space') ?: null,
                retries: (int) $config->get('wisedata-mail.retries', 2),
            );
        });

        $this->registrarMailer();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/wisedata-mail.php' => $this->app->configPath('wisedata-mail.php'),
            ], 'wisedata-mail-config');
        }

        $this->estenderMail();
    }

    /**
     * Declara o mailer para o `MAIL_MAILER=wisedatamail` bastar.
     *
     * O `MailManager` lê `config('mail.mailers.wisedatamail')` e lança
     * `Mailer [wisedatamail] is not defined` quando não acha. Definindo aqui,
     * instalar o pacote e trocar uma linha do `.env` já envia — ninguém precisa
     * abrir o `config/mail.php`.
     *
     * Só define quando a chave não existe: quem editou o arquivo mandou, e é lá
     * que se configura um segundo mailer para outro espaço ou outro tipo.
     */
    private function registrarMailer(): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $this->app->make('config');

        if ($config->get('mail.mailers.wisedatamail') === null) {
            $config->set('mail.mailers.wisedatamail', ['transport' => 'wisedatamail']);
        }
    }

    /**
     * Ensina o `MailManager` a construir o nosso transport.
     *
     * `Mail::extend()` e não uma `TransportFactory` com DSN: o `MailManager` do
     * Laravel consulta os criadores registrados por `extend` ANTES dos drivers
     * nativos, e nunca olha para o mecanismo de DSN do Symfony. Uma factory
     * seria código sem consumidor.
     *
     * O guard existe porque `symfony/mailer` não é dependência de produção deste
     * pacote. Ele está presente em qualquer aplicação Laravel — vem com
     * `illuminate/mail` —, mas quem usar o pacote só para sincronizar contato
     * fora do Laravel não pode ver o provider explodir por uma classe de mailer
     * que não vai usar.
     */
    private function estenderMail(): void
    {
        if (! class_exists(AbstractTransport::class) || ! class_exists(Mail::class)) {
            return;
        }

        Mail::extend('wisedatamail', function (array $config): WiseDataMailTransport {
            /** @var \Illuminate\Contracts\Config\Repository $global */
            $global = $this->app->make('config');

            $espaco = $config['space'] ?? $global->get('wisedata-mail.space');
            $tipo = $config['message_type'] ?? $global->get('wisedata-mail.mail.message_type');

            return new WiseDataMailTransport(
                new Client(
                    token: (string) $global->get('wisedata-mail.token', ''),
                    baseUrl: (string) $global->get('wisedata-mail.base_url', 'https://api.wisedatamail.com'),
                    space: is_string($espaco) && $espaco !== '' ? $espaco : null,

                    /*
                     * Zero, e é deliberado. O `Client` repete qualquer verbo em
                     * 429 e 5xx com uma espera BLOQUEANTE — num `POST` de envio
                     * isso é e-mail duplicado (o servidor pode ter aceitado e
                     * caído ao responder) e uma requisição web travada por
                     * segundos. Quem repete é a fila do Laravel, que tem backoff
                     * próprio e é a única a repetir.
                     */
                    retries: (int) $global->get('wisedata-mail.mail.retries', 0),
                ),
                is_string($tipo) && $tipo !== '' ? $tipo : null,
            );
        });
    }
}
