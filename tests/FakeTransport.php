<?php

declare(strict_types=1);

namespace WiseData\Mail\Tests;

use WiseData\Mail\Contracts\Transport;
use WiseData\Mail\Exception\TransportException;
use WiseData\Mail\Http\Response;

/**
 * O transporte dos testes: devolve respostas programadas e grava o que recebeu.
 *
 * É a razão de `Transport` ser interface. Sem ele, provar que o cliente monta
 * a URL certa e manda o cabeçalho do espaço exigiria um servidor de verdade —
 * e o teste passaria a falhar por causa da rede.
 */
final class FakeTransport implements Transport
{
    /** @var list<Response|TransportException> */
    private array $respostas = [];

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?array<string, mixed>}> */
    public array $chamadas = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function responde(int $status, array $data = []): self
    {
        $this->respostas[] = new Response($status, $data);

        return $this;
    }

    public function falha(string $mensagem = 'sem rede'): self
    {
        $this->respostas[] = new TransportException($mensagem);

        return $this;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  ?array<string, mixed>  $body
     */
    public function send(string $method, string $url, array $headers, ?array $body): Response
    {
        $this->chamadas[] = compact('method', 'url', 'headers', 'body');

        $proxima = array_shift($this->respostas);

        if ($proxima === null) {
            throw new TransportException("O teste não programou resposta para {$method} {$url}.");
        }

        if ($proxima instanceof TransportException) {
            throw $proxima;
        }

        return $proxima;
    }

    /**
     * @return array{method: string, url: string, headers: array<string, string>, body: ?array<string, mixed>}
     */
    public function ultima(): array
    {
        $ultima = end($this->chamadas);

        if ($ultima === false) {
            throw new TransportException('Nenhuma chamada foi feita.');
        }

        return $ultima;
    }
}
