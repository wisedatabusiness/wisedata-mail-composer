<?php

declare(strict_types=1);

namespace WiseData\Mail\Tests;

use PHPUnit\Framework\TestCase;
use WiseData\Mail\Client;
use WiseData\Mail\Exception\ApiException;

/**
 * A validação da API (remetente não verificado, campo faltando) responde no
 * formato do Laravel, sem `error`. Sem o código do pacote, o `if` de quem integra
 * só teria `unknown_error` para olhar.
 */
final class ValidationErrorTest extends TestCase
{
    public function test_422_de_validacao_vira_validation_failed_com_os_campos(): void
    {
        $http = (new FakeTransport)->responde(422, [
            'message' => 'O remetente não está verificado.',
            'errors' => ['from' => ['O remetente não está verificado.']],
        ]);

        try {
            (new Client('wdm_abc_def', transport: $http))->emails()->send(['to' => 'ana@exemplo.com']);
            $this->fail('a recusa precisa virar exceção');
        } catch (ApiException $e) {
            $this->assertSame(ApiException::VALIDACAO, $e->error);
            $this->assertSame(['from' => ['O remetente não está verificado.']], $e->extra['errors'] ?? null);
        }
    }

    public function test_o_codigo_da_api_prevalece_quando_vem(): void
    {
        $http = (new FakeTransport)->responde(422, ['error' => 'unknown_field', 'message' => 'x', 'errors' => []]);

        try {
            (new Client('wdm_abc_def', transport: $http))->emails()->send(['to' => 'ana@exemplo.com']);
            $this->fail('a recusa precisa virar exceção');
        } catch (ApiException $e) {
            $this->assertSame('unknown_field', $e->error);
        }
    }
}
