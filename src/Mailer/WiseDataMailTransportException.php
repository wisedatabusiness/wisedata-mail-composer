<?php

declare(strict_types=1);

namespace WiseData\Mail\Mailer;

use Symfony\Component\Mailer\Exception\TransportException;
use Throwable;

/**
 * A falha de envio, no formato que o Laravel e o Symfony entendem.
 *
 * Estende a `TransportException` do Symfony — que implementa
 * `TransportExceptionInterface` — e é isso que faz o `Mail::` da aplicação se
 * comportar como sempre: o job falha, o evento `MessageSendingFailed` dispara, e
 * a mensagem vai para `failed_jobs` com o motivo. Uma exceção nossa, fora dessa
 * hierarquia, atravessaria o mailer como erro inesperado.
 *
 * `$error` carrega o código estável da API (`send_quota_exceeded`,
 * `insufficient_scope`). É por ele que a aplicação decide o que fazer, e não
 * pela mensagem — que é traduzida e pode ser reescrita a qualquer momento.
 */
final class WiseDataMailTransportException extends TransportException
{
    public function __construct(
        string $message,
        public readonly ?string $error = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
