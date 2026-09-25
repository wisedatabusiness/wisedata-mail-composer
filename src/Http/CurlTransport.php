<?php

declare(strict_types=1);

namespace WiseData\Mail\Http;

use WiseData\Mail\Contracts\Transport;
use WiseData\Mail\Exception\TransportException;

/**
 * O transporte padrão: cURL, que já vem no PHP.
 *
 * Nenhuma dependência de propósito — ver o docblock do `Transport`. Quem já tem
 * Guzzle ou o cliente do Laravel implementa a interface e injeta.
 */
final class CurlTransport implements Transport
{
    public function __construct(
        private readonly int $timeout = 15,
        private readonly int $connectTimeout = 5,
    ) {}

    /**
     * @param  array<string, string>  $headers
     * @param  ?array<string, mixed>  $body
     */
    public function send(string $method, string $url, array $headers, ?array $body): Response
    {
        $curl = curl_init();

        if ($curl === false) {
            throw new TransportException('Não foi possível iniciar o cURL.');
        }

        $opcoes = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,

            /*
             * Sem seguir redirecionamento: a API nunca redireciona, então um
             * 301 aqui só aconteceria se alguém tivesse assumido o DNS ou
             * errado a URL base — e seguir levaria o token junto para onde quer
             * que ele aponte.
             */
            CURLOPT_FOLLOWLOCATION => false,

            /*
             * Já é o padrão do cURL, e é declarado assim mesmo: um `php.ini` que
             * desligou a verificação — o que acontece em servidor onde alguém
             * "resolveu" um erro de certificado — faria o token sair para
             * qualquer um que apresentasse um certificado forjado, sem nada na
             * aplicação indicando isso. Aqui a decisão é do pacote, não do host.
             */
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,

            CURLOPT_HTTPHEADER => $this->cabecalhos($headers),
        ];

        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($json === false) {
                throw new TransportException('O corpo da requisição não pôde virar JSON: '.json_last_error_msg());
            }

            $opcoes[CURLOPT_POSTFIELDS] = $json;
        }

        curl_setopt_array($curl, $opcoes);

        $corpo = curl_exec($curl);
        $erro = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        curl_close($curl);

        if ($corpo === false) {
            throw new TransportException("A requisição não chegou ao WiseData Mail: {$erro}");
        }

        return new Response($status, $this->decodificado((string) $corpo), (string) $corpo);
    }

    /**
     * @param  array<string, string>  $headers
     * @return list<string>
     */
    private function cabecalhos(array $headers): array
    {
        $linhas = [];

        foreach ($headers as $nome => $valor) {
            $linhas[] = "{$nome}: {$valor}";
        }

        return $linhas;
    }

    /**
     * Corpo que não é JSON vira array vazio, e não exceção.
     *
     * Um 204 não tem corpo, e um 502 do balanceador devolve HTML. Nos dois
     * casos quem decide o que fazer é o `Client`, olhando o status — estourar
     * aqui trocaria "a API respondeu 502" por "o JSON está malformado", que
     * manda quem integra procurar o problema no lugar errado.
     *
     * @return array<string, mixed>
     */
    private function decodificado(string $corpo): array
    {
        if (trim($corpo) === '') {
            return [];
        }

        $dados = json_decode($corpo, true);

        return is_array($dados) ? $dados : [];
    }
}
