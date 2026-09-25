<?php

declare(strict_types=1);

namespace WiseData\Mail\Contracts;

use WiseData\Mail\Http\Response;

/**
 * Como a requisição sai daqui.
 *
 * A interface existe porque este pacote é **agnóstico de biblioteca HTTP**: um
 * produto que já usa Guzzle, outro que usa o cliente do Laravel e um terceiro
 * que não usa nada precisam instalar o mesmo pacote sem herdar a escolha dos
 * outros. O padrão é cURL, que vem no PHP.
 *
 * É também o que torna o pacote testável sem rede: o teste injeta um transporte
 * que devolve a resposta gravada.
 */
interface Transport
{
    /**
     * @param  array<string, string>  $headers
     * @param  ?array<string, mixed>  $body  `null` não envia corpo
     */
    public function send(string $method, string $url, array $headers, ?array $body): Response;
}
