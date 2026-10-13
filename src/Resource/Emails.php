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

    /**
     * Até 100 mensagens numa chamada, cada uma com o seu destinatário e o seu desfecho.
     *
     * ```php
     * $resultados = $mail->emails()->sendBatch([
     *     ['to' => 'ana@exemplo.com', 'subject' => 'Sua opinião', 'html' => '...',
     *      'idempotency_key' => 'convite-81', 'metadata' => ['invitation_id' => 81]],
     *     ['to' => 'bia@exemplo.com', 'subject' => 'Sua opinião', 'html' => '...',
     *      'idempotency_key' => 'convite-82', 'metadata' => ['invitation_id' => 82]],
     * ]);
     * ```
     *
     * Cada item aceita os campos de `send()`, menos anexo, e mais
     * `idempotency_key` — a chave é por mensagem, no corpo, porque o cabeçalho
     * da requisição não diria qual das cem já tinha saído.
     *
     * Devolve um item por mensagem, na ordem do pedido e com `index`:
     * - aceita: os campos de `send()` (`id`, `status`...) e `replayed` (`true`
     *   quando a chave de idempotência reencontrou um envio anterior);
     * - recusada: `status` com o código HTTP que o envio avulso daria e `error`
     *   com o mesmo corpo de erro (`error`, `message`...). Suprimida, sem cota e
     *   modelo inexistente chegam assim — o resto do lote sai normalmente.
     *
     * A chamada só lança quando o lote INTEIRO é recusado: estrutura inválida
     * (422, apontando `emails.3.to`) ou conta sem condição de enviar.
     *
     * @param  list<array<string, mixed>>  $emails
     * @return list<array<string, mixed>>
     *
     * @throws ApiException
     */
    public function sendBatch(array $emails): array
    {
        $resposta = $this->client->request('POST', 'emails/batch', body: ['emails' => $emails]);

        $itens = [];

        foreach (is_array($resposta['data'] ?? null) ? $resposta['data'] : [] as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $itens[] = $item;
            }
        }

        return $itens;
    }
}
