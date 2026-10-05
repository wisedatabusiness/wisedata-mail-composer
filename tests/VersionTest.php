<?php

declare(strict_types=1);

namespace WiseData\Mail\Tests;

use PHPUnit\Framework\TestCase;
use WiseData\Mail\Client;

/**
 * `Client::VERSION` vai no `User-Agent` e é por ele que a API conta quem ainda
 * está numa versão antiga. Já saiu `0.3.0` na v1.0.1; este teste prende a
 * constante à versão mais recente do CHANGELOG, e o CI a prende à tag.
 */
final class VersionTest extends TestCase
{
    public function test_a_versao_do_cliente_e_a_ultima_do_changelog(): void
    {
        $changelog = file_get_contents(__DIR__.'/../CHANGELOG.md');
        $this->assertIsString($changelog);

        $this->assertSame(1, preg_match('/^## \[(\d+\.\d+\.\d+)\]/m', $changelog, $versao));
        $this->assertSame($versao[1], Client::VERSION);
    }
}
