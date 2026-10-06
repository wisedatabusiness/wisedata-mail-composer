<?php

declare(strict_types=1);

namespace WiseData\Mail\Testing;

use PHPUnit\Framework\Assert;
use WiseData\Mail\Contracts\Transport;
use WiseData\Mail\Http\Response;
use WiseData\Mail\Validation\SendRules;

/**
 * Substitui só a fronteira HTTP: o `Client` e o transport do mailer continuam os
 * reais, e o que iria para a API fica gravado.
 *
 * No Laravel, ligue com `WiseDataMail::fake()`. Fora dele, injete no `Client`:
 * `new Client('wdm_x', transport: $fake = new WiseDataMailFake)`.
 *
 * Todo envio é aceito com `202` e um `id` sequencial, a não ser depois de
 * `failWith()` — ou quando o corpo fere as regras da API (nome de cabeçalho,
 * `tags`, `metadata`): aí volta o mesmo `422 validation_failed` que ela daria.
 */
final class WiseDataMailFake implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?array<string, mixed>}> */
    private array $requests = [];

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    private ?Response $failure = null;

    private int $lastId = 0;

    /**
     * @param  array<string, string>  $headers
     * @param  ?array<string, mixed>  $body
     */
    public function send(string $method, string $url, array $headers, ?array $body): Response
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');

        if ($this->failure !== null) {
            return $this->failure;
        }

        if (! $this->isEmailSend($method, $url)) {
            return new Response(200, ['data' => []]);
        }

        /*
         * A fake recusa o que a API recusaria. Aceitar tudo foi o que deixou um
         * `X-Metadata-user_id` passar no CI da aplicação e falhar só em produção.
         */
        $violacoes = SendRules::violations($body ?? []);

        if ($violacoes !== []) {
            return new Response(422, [
                'error' => 'validation_failed',
                'message' => 'Recusado pelo WiseDataMailFake com as regras da API.',
                'errors' => $violacoes,
            ]);
        }

        $this->sent[] = $body ?? [];
        $this->lastId++;

        return new Response(202, ['data' => [
            'id' => $this->lastId,
            'to' => $body['to'] ?? null,
            /* Com `template` a API devolve o assunto renderizado, que a fake não conhece. */
            'subject' => is_string($body['subject'] ?? null) ? $body['subject'] : null,
            'status' => 'pending',
            'provider_message_id' => null,
        ]]);
    }

    /**
     * Faz toda chamada seguinte ser recusada como a API recusaria.
     *
     * @param  array<string, mixed>  $extra  campos extras do corpo (`required_scope`, `errors`, ...)
     */
    public function failWith(string $error, int $status = 422, string $message = 'Recusado pelo WiseDataMailFake.', array $extra = []): self
    {
        $this->failure = new Response($status, [...$extra, 'error' => $error, 'message' => $message]);

        return $this;
    }

    /**
     * Os corpos dos envios aceitos (`to`, `subject`, `html`, `text`, `from`, ...).
     *
     * @param  ?callable(array<string, mixed>): bool  $filter
     * @return list<array<string, mixed>>
     */
    public function sent(?callable $filter = null): array
    {
        return $filter === null ? $this->sent : array_values(array_filter($this->sent, $filter));
    }

    /**
     * Todas as chamadas, inclusive as recusadas e as de contato.
     *
     * @return list<array{method: string, url: string, headers: array<string, string>, body: ?array<string, mixed>}>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * @param  ?callable(array<string, mixed>): bool  $callback
     */
    public function assertSent(?callable $callback = null): void
    {
        Assert::assertNotEmpty($this->sent($callback), 'Nenhum e-mail esperado foi enviado pelo WiseData Mail.');
    }

    /**
     * @param  ?callable(array<string, mixed>): bool  $callback
     */
    public function assertSentTo(string $email, ?callable $callback = null): void
    {
        Assert::assertNotEmpty(
            $this->sentTo($email, $callback),
            "Nenhum e-mail esperado foi enviado para {$email} pelo WiseData Mail.",
        );
    }

    public function assertNotSentTo(string $email): void
    {
        Assert::assertEmpty($this->sentTo($email), "Um e-mail foi enviado para {$email} pelo WiseData Mail.");
    }

    public function assertSentCount(int $count): void
    {
        Assert::assertCount($count, $this->sent, "Esperava {$count} envio(s) pelo WiseData Mail.");
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->sent, 'Nenhum e-mail deveria ter sido enviado pelo WiseData Mail.');
    }

    /**
     * @param  ?callable(array<string, mixed>): bool  $callback
     * @return list<array<string, mixed>>
     */
    private function sentTo(string $email, ?callable $callback = null): array
    {
        return $this->sent(static fn (array $corpo): bool => is_string($corpo['to'] ?? null)
            && strcasecmp($corpo['to'], $email) === 0
            && ($callback === null || $callback($corpo)));
    }

    private function isEmailSend(string $method, string $url): bool
    {
        $caminho = (string) parse_url($url, PHP_URL_PATH);

        return strtoupper($method) === 'POST' && str_ends_with(rtrim($caminho, '/'), '/v1/emails');
    }
}
