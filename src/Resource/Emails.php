<?php

declare(strict_types=1);

namespace WiseData\Mail\Resource;

use WiseData\Mail\Client;
use WiseData\Mail\Exception\ApiException;

/**
 * O disparo transacional: recibo, código de verificação, redefinição de senha.
 *
 * ```php
 * $mail->emails()->send([
 *     'to' => 'ana@exemplo.com',
 *     'subject' => 'Seu código',
 *     'html' => '<p>Seu código é 123456.</p>',
 *     'text' => 'Seu código é 123456.',
 * ], idempotencyKey: 'codigo-usr9-2026-09-24');
 * ```
 *
 * Em Laravel, o caminho mais curto não é este: é `MAIL_MAILER=wisedatamail` no
 * `.env` e o `Mail::to()` que o projeto já tem. Este recurso é para quem quer
 * controle do corpo da chamada, ou não está em Laravel.
 */
final class Emails
{
    public function __construct(private readonly Client $client) {}

    /**
     * Dispara uma mensagem e devolve o registro dela.
     *
     * A resposta traz `id` — que é por onde o envio é acompanhado — e `status`:
     * `pending` quando entrou na fila, `suppressed` quando o endereço está na
     * lista de supressão da conta. **Suprimido não é erro**: a chamada foi
     * aceita, e a mensagem não vai sair porque aquela pessoa pediu para não
     * receber, devolveu ou reclamou. Nada é cobrado da cota.
     *
     * ## A chave de idempotência
     *
     * Mandar a mesma chave de novo devolve o MESMO registro, sem reenviar. É o
     * que permite reprocessar uma fila depois de uma queda sem duplicar e-mail —
     * e é a única proteção real contra isso, porque uma repetição de rede é
     * indistinguível de um pedido novo.
     *
     * Use um valor derivado do evento que originou a mensagem
     * (`pedido-1234-confirmado`), nunca um aleatório: um `uuid()` gerado na hora
     * da chamada muda a cada tentativa e não protege de nada.
     *
     * @param  array<string, mixed>  $email
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function send(array $email, ?string $idempotencyKey = null): array
    {
        $cabecalhos = $idempotencyKey !== null && $idempotencyKey !== ''
            ? ['Idempotency-Key' => $idempotencyKey]
            : [];

        $resposta = $this->client->request('POST', 'emails', body: $email, headers: $cabecalhos);

        $dados = $resposta['data'] ?? [];

        return is_array($dados) ? $dados : [];
    }
}
