<?php

declare(strict_types=1);

namespace WiseData\Mail\Tests\Laravel;

use Orchestra\Testbench\TestCase as Orchestra;
use WiseData\Mail\Laravel\WiseDataMailServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [WiseDataMailServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('wisedata-mail.token', 'wdm_teste_token');
        $app['config']->set('mail.from', ['address' => 'avisos@exemplo.com', 'name' => 'Exemplo']);
    }
}
