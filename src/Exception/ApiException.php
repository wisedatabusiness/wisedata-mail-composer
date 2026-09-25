<?php

declare(strict_types=1);

namespace WiseData\Mail\Exception;

use WiseData\Mail\Http\Response;

/**
 * A API respondeu, e respondeu não.
 *
 * `$error` é o código estável (`invalid_api_key`, `unknown_field`, …) e é por
 * ele que o `if` de quem integra decide. A `message` é texto para gente, sai no
 * idioma do `Accept-Language` e pode mudar a qualquer momento — programar
 * contra ela é programar contra uma tradução.
 */
class ApiException extends WiseDataMailException
{
    /**
     * @param  array<string, mixed>  $extra  os campos que ajudam a consertar a chamada
     */
    public function __construct(
        public readonly string $error,
        public readonly int $status,
        string $message,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(Response $resposta): self
    {
        $corpo = $resposta->data;

        $error = is_string($corpo['error'] ?? null) ? $corpo['error'] : 'unknown_error';
        $mensagem = is_string($corpo['message'] ?? null)
            ? $corpo['message']
            : "A API respondeu {$resposta->status} sem uma mensagem legível.";

        unset($corpo['error'], $corpo['message']);

        return new self($error, $resposta->status, $mensagem, $corpo);
    }

    /**
     * O escopo que faltava, quando o erro foi `insufficient_scope`.
     *
     * Atalho com nome, em vez de `->extra['required_scope']`: é o campo que
     * quem integra lê no minuto em que o erro aparece, e o array solto convida
     * a errar o nome da chave.
     */
    public function requiredScope(): ?string
    {
        $escopo = $this->extra['required_scope'] ?? null;

        return is_string($escopo) ? $escopo : null;
    }
}
