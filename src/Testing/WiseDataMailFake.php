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

        if ($this->isBatchSend($method, $url)) {
            return $this->lote($body ?? []);
        }

        $cadastro = $this->cadastro($method, $url, $body ?? []);

        if ($cadastro !== null) {
            return $cadastro;
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
            return $this->recusa($violacoes);
        }

        return new Response(202, ['data' => $this->aceitar($body ?? [])]);
    }

    /**
     * O lote: tudo ou nada na estrutura, como a API. Cada mensagem aceita entra em
     * `sent()` — o teste da aplicação confere o lote com as mesmas asserções do avulso.
     *
     * @param  array<string, mixed>  $body
     */
    private function lote(array $body): Response
    {
        $emails = is_array($body['emails'] ?? null) ? array_values($body['emails']) : [];
        $violacoes = SendRules::batchViolations($emails);

        if ($violacoes !== []) {
            return $this->recusa($violacoes);
        }

        $itens = [];

        foreach ($emails as $indice => $email) {
            /** @var array<string, mixed> $email */
            $itens[] = ['index' => $indice, 'replayed' => false, ...$this->aceitar($email)];
        }

        return new Response(200, ['data' => $itens]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function aceitar(array $body): array
    {
        $this->sent[] = $body;
        $this->lastId++;

        return [
            'id' => $this->lastId,
            'to' => $body['to'] ?? null,
            /* Com `template` a API devolve o assunto renderizado, que a fake não conhece. */
            'subject' => is_string($body['subject'] ?? null) ? $body['subject'] : null,
            'status' => 'pending',
            'provider_message_id' => null,
        ];
    }

    /**
     * O `PUT` de espaço, domínio e remetente, respondido no formato da API.
     *
     * Sem isto, `spaces()->upsert()` devolveria `[]` e o código que lê o `slug`
     * para chamar `forSpace()` quebraria só no teste — ou, pior, passaria com
     * um `forSpace('')` que em produção é a conta principal.
     *
     * O `slug` da fake é o próprio `external_id`; o da API é gerado a partir
     * do nome. Guarde o que vier na resposta, nunca o que você mandou.
     *
     * @param  array<string, mixed>  $body
     */
    private function cadastro(string $method, string $url, array $body): ?Response
    {
        $recurso = strtoupper($method) === 'PUT' ? $this->recursoDe($url) : null;

        return match ($recurso) {
            'spaces' => new Response(201, ['data' => [
                'external_id' => $body['external_id'] ?? null,
                'slug' => $body['external_id'] ?? null,
                'name' => $body['name'] ?? null,
                'send_limit' => $body['send_limit'] ?? null,
                'used' => 0,
                'created_at' => null,
            ]]),
            'domains' => new Response(201, ['data' => [
                'domain' => $body['domain'] ?? null,
                'ready_to_send' => false,
                'verified_at' => null,
                'last_checked_at' => null,
                'checking' => true,
                'failed_records' => ['ownership', 'dkim', 'mail_from', 'tracking', 'dmarc'],
                'records' => [],
            ]]),
            'senders' => new Response(201, ['data' => [
                'from_email' => $body['from_email'] ?? null,
                'from_name' => $body['from_name'] ?? null,
                'reply_to' => $body['reply_to'] ?? null,
                'label' => $body['label'] ?? null,
                'verified' => false,
                'verified_at' => null,
            ]]),
            default => null,
        };
    }

    private function recursoDe(string $url): ?string
    {
        $caminho = rtrim((string) parse_url($url, PHP_URL_PATH), '/');

        foreach (['spaces', 'domains', 'senders'] as $recurso) {
            if (str_ends_with($caminho, "/v1/{$recurso}")) {
                return $recurso;
            }
        }

        return null;
    }

    /**
     * O espaço foi criado (ou atualizado) com este `external_id`.
     */
    public function assertSpaceUpserted(string $externalId): void
    {
        Assert::assertNotEmpty(
            $this->cadastros('spaces', 'external_id', $externalId),
            "Nenhum espaço com external_id {$externalId} foi gravado pelo WiseData Mail.",
        );
    }

    /**
     * O domínio foi cadastrado — e, com `$space`, no espaço certo. Cadastrar
     * o domínio do cliente na conta principal é o erro que este parâmetro pega.
     */
    public function assertDomainRegistered(string $domain, ?string $space = null): void
    {
        Assert::assertNotEmpty(
            $this->cadastros('domains', 'domain', $domain, $space),
            "O domínio {$domain} não foi cadastrado".($space === null ? '' : " no espaço {$space}").' pelo WiseData Mail.',
        );
    }

    public function assertSenderUpserted(string $fromEmail, ?string $space = null): void
    {
        Assert::assertNotEmpty(
            $this->cadastros('senders', 'from_email', $fromEmail, $space),
            "O remetente {$fromEmail} não foi gravado".($space === null ? '' : " no espaço {$space}").' pelo WiseData Mail.',
        );
    }

    /**
     * @return list<array{method: string, url: string, headers: array<string, string>, body: ?array<string, mixed>}>
     */
    private function cadastros(string $recurso, string $campo, string $valor, ?string $space = null): array
    {
        return array_values(array_filter($this->requests, fn (array $chamada): bool => strtoupper($chamada['method']) === 'PUT'
            && $this->recursoDe($chamada['url']) === $recurso
            && is_string($chamada['body'][$campo] ?? null)
            && strcasecmp($chamada['body'][$campo], $valor) === 0
            && ($space === null || ($chamada['headers']['X-WiseData-Space'] ?? null) === $space)));
    }

    /**
     * @param  array<string, list<string>>  $violacoes
     */
    private function recusa(array $violacoes): Response
    {
        return new Response(422, [
            'error' => 'validation_failed',
            'message' => 'Recusado pelo WiseDataMailFake com as regras da API.',
            'errors' => $violacoes,
        ]);
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

    private function isBatchSend(string $method, string $url): bool
    {
        $caminho = (string) parse_url($url, PHP_URL_PATH);

        return strtoupper($method) === 'POST' && str_ends_with(rtrim($caminho, '/'), '/v1/emails/batch');
    }
}
