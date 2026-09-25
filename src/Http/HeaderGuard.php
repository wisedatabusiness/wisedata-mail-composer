<?php

declare(strict_types=1);

namespace WiseData\Mail\Http;

use WiseData\Mail\Exception\TransportException;

/**
 * O que pode virar cabeçalho HTTP.
 *
 * ## O ataque que isto fecha
 *
 * Um cabeçalho é uma linha terminada em `\r\n`. Quem controlar parte do VALOR e
 * conseguir pôr um `\r\n` dentro não escreve um valor: escreve um cabeçalho
 * novo. Medido contra um servidor local, `pedido-1\r\nX-WiseData-Space: outro`
 * como chave de idempotência chegou ao servidor como dois cabeçalhos — e o
 * segundo é o que decide em qual espaço de envio a escrita cai. O libcurl não
 * confere nada; a linha sai como foi montada.
 *
 * Não é hipótese de laboratório: o valor mais exposto do pacote é justamente a
 * chave de idempotência, que a documentação manda derivar de dado do domínio
 * de quem integra (`pedido-1234-confirmado`) — ou seja, de algo que muitas
 * vezes veio de um formulário.
 *
 * ## Recusar, nunca limpar
 *
 * Tirar os `\r\n` e seguir seria pior. A chave de idempotência mutilada vira
 * OUTRA chave, e a proteção contra envio duplicado — que é a razão de ela
 * existir — deixa de valer em silêncio, justamente na chamada de quem tentou
 * abusar. Recusar alto é a única saída em que ninguém perde garantia sem saber.
 *
 * ## Por que classe própria
 *
 * A regra tem dois consumidores — o `Client`, que vale para qualquer
 * transporte, e o `CurlTransport`, que é a última linha antes do fio. Escrita
 * duas vezes, ela divergiria; e num lugar só ela não daria defesa em
 * profundidade a quem injeta um transporte próprio.
 */
final class HeaderGuard
{
    /**
     * Devolve os cabeçalhos, ou lança.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     *
     * @throws TransportException
     */
    public static function validated(array $headers): array
    {
        foreach ($headers as $nome => $valor) {
            self::conferirNome((string) $nome);
            self::conferirValor((string) $nome, $valor);
        }

        return $headers;
    }

    /**
     * @throws TransportException
     */
    private static function conferirNome(string $nome): void
    {
        /*
         * O token de cabeçalho da RFC 9110. Espaço inclusive é proibido: o
         * `Nome : valor` que parece inofensivo já é outro cabeçalho para parte
         * dos servidores e nenhum para o resto.
         */
        if (preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $nome) !== 1) {
            throw new TransportException(
                "Nome de cabeçalho inválido: \"{$nome}\". Só letras, dígitos e os sinais que a RFC 9110 permite."
            );
        }
    }

    /**
     * @throws TransportException
     */
    private static function conferirValor(string $nome, string $valor): void
    {
        /*
         * Qualquer caractere de controle, não só `\r\n`. O `\n` sozinho basta
         * para uma parte dos servidores, e o byte nulo trunca a linha em
         * qualquer coisa escrita em C — o libcurl é escrito em C.
         */
        if (preg_match('/[\x00-\x1F\x7F]/', $valor) !== 1) {
            return;
        }

        throw new TransportException(
            "O valor do cabeçalho \"{$nome}\" tem caractere de controle, e nada foi enviado. "
            .'Uma quebra de linha aí não vira texto: vira um cabeçalho novo, escrito por quem '
            .'preencheu o valor. Se ele veio de dado da sua aplicação, limpe-o na origem.'
        );
    }
}
