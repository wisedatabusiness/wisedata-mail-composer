<?php

declare(strict_types=1);

namespace WiseData\Mail\Laravel\Facades;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use RuntimeException;
use WiseData\Mail\Client;
use WiseData\Mail\Contracts\Transport;
use WiseData\Mail\Resource\Catalog;
use WiseData\Mail\Resource\Contacts;
use WiseData\Mail\Resource\Emails;
use WiseData\Mail\Testing\WiseDataMailFake;

/**
 * @method static Contacts contacts()
 * @method static Catalog catalog()
 * @method static Emails emails()
 * @method static Client forSpace(?string $space)
 *
 * @see Client
 */
final class WiseDataMail extends Facade
{
    /**
     * Troca a fronteira HTTP pela fake, para o `Client` do contêiner e para todo
     * mailer `wisedatamail`.
     *
     * Os mailers já construídos são descartados: o `MailManager` guarda a
     * instância, e ela seguiria falando com a API de verdade.
     */
    public static function fake(): WiseDataMailFake
    {
        $app = self::getFacadeApplication();

        if (! $app instanceof Container) {
            throw new RuntimeException('WiseDataMail::fake() precisa da aplicação Laravel em pé.');
        }

        $fake = new WiseDataMailFake;

        $app->instance(Transport::class, $fake);
        $app->forgetInstance(Client::class);
        self::clearResolvedInstance(Client::class);

        $config = $app->make('config');

        /* Sem token o `Client` recusa a construção; num teste o valor não importa. */
        if (! is_string($config->get('wisedata-mail.token')) || $config->get('wisedata-mail.token') === '') {
            $config->set('wisedata-mail.token', 'wdm_fake_token');
        }

        if ($app->bound('mail.manager')) {
            $mail = $app->make('mail.manager');

            foreach ((array) $config->get('mail.mailers', []) as $nome => $mailer) {
                if (is_array($mailer) && ($mailer['transport'] ?? null) === 'wisedatamail') {
                    $mail->purge((string) $nome);
                }
            }
        }

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
