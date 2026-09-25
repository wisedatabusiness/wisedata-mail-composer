<?php

declare(strict_types=1);

namespace WiseData\Mail;

use WiseData\Mail\Contracts\Transport;
use WiseData\Mail\Exception\ApiException;
use WiseData\Mail\Exception\TransportException;
use WiseData\Mail\Http\CurlTransport;
use WiseData\Mail\Http\HeaderGuard;
use WiseData\Mail\Http\Response;
use WiseData\Mail\Resource\Catalog;
use WiseData\Mail\Resource\Contacts;
use WiseData\Mail\Resource\Emails;

/**
 * O cliente da API do WiseData Mail.
 *
 * ```php
 * $mail = new Client('wdm_abc_def');
 *
 * $mail->contacts()->upsert([
 *     'external_id' => 'usr_9',
 *     'email' => 'ana@exemplo.com',
 *     'fields' => ['plano_atual' => 'pro'],
 * ]);
 * ```
 *
 * ## Espaços de envio
 *
 * Uma chave pode alcançar vários espaços. `forSpace()` devolve um clone
 * apontando para um deles — clone, e não mutação, porque um produto que
 * atende três marcas guarda os três clientes em variáveis diferentes, e
 * um `setSpace()` faria a última chamada mudar para onde a anterior grava.
 */
final class Client
{
    public const VERSION = '0.3.0';

    private const URL_PADRAO = 'https://api.wisedatamail.com';

    private readonly Transport $transport;

    /**
     * @param  string  $token  o `wdm_...` da tela de Integrações
     * @param  ?string  $space  slug do espaço de envio, quando a chave alcança mais de um
     * @param  int  $retries  tentativas EXTRA em 429, 5xx e falha de rede
     */
    public function __construct(
        private readonly string $token,
        private readonly string $baseUrl = self::URL_PADRAO,
        private readonly ?string $space = null,
        private readonly int $retries = 2,
        ?Transport $transport = null,
    ) {
        if (trim($token) === '') {
            throw new TransportException('O token da API está vazio. Pegue-o em Configurações › Integrações.');
        }

        $this->exigirTls($baseUrl);

        $this->transport = $transport ?? new CurlTransport;
    }

    /**
     * O token vale tanto quanto a senha da conta e vai em TODA requisição.
     *
     * Um `WISEDATA_MAIL_URL` sem TLS — o valor de desenvolvimento que sobreviveu
     * ao deploy é o caso real — o entrega em claro a qualquer um no caminho, sem
     * nada na aplicação sugerindo que algo está errado. Recusar na construção é
     * o único momento em que isso aparece antes de o token já ter vazado.
     *
     * A exceção é o endereço local, onde não há caminho para escutar e onde
     * exigir certificado só faria todo mundo desligar a verificação.
     *
     * @throws TransportException
     */
    private function exigirTls(string $baseUrl): void
    {
        $esquema = strtolower((string) (parse_url($baseUrl, PHP_URL_SCHEME) ?: ''));

        if ($esquema === 'https') {
            return;
        }

        $host = strtolower((string) (parse_url($baseUrl, PHP_URL_HOST) ?: ''));

        $local = $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test');

        if ($esquema === 'http' && $local) {
            return;
        }

        throw new TransportException(
            "O endereço da API precisa ser https, e veio \"{$baseUrl}\". Sem TLS o token da conta "
            .'viaja legível em toda requisição. Só um endereço local (localhost, 127.0.0.1, .test) '
            .'pode usar http.'
        );
    }

    /**
     * O que `dd()`, `dump()` e `var_dump()` mostram deste objeto.
     *
     * Sem isto o token sai por inteiro na tela de erro do Laravel, no `dd()` de
     * quem está depurando e na captura que essa pessoa cola no chat do time. É
     * o mesmo cuidado do `$hidden` de um model: o segredo não vaza por ataque,
     * vaza por depuração.
     *
     * Sobra o `print_r()`, que ignora este método por decisão do PHP. Não há
     * como cobri-lo sem abrir mão de propriedade privada; quem precisa inspecionar
     * o cliente usa `dump()`.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'token' => $this->mascarado(),
            'baseUrl' => $this->baseUrl,
            'space' => $this->space,
            'retries' => $this->retries,
            'transport' => $this->transport::class,
        ];
    }

    /** O bastante para saber QUAL chave é, sem entregar a chave. */
    private function mascarado(): string
    {
        return substr($this->token, 0, 8).'…'.substr($this->token, -4);
    }

    public function contacts(): Contacts
    {
        return new Contacts($this);
    }

    public function catalog(): Catalog
    {
        return new Catalog($this);
    }

    public function emails(): Emails
    {
        return new Emails($this);
    }

    /** Um cliente igual a este, apontando para outro espaço de envio. */
    public function forSpace(?string $space): self
    {
        return new self($this->token, $this->baseUrl, $space, $this->retries, $this->transport);
    }

    /**
     * Faz a chamada e devolve o corpo — ou lança.
     *
     * Todo método de recurso passa por aqui, então é o único lugar que precisa
     * saber montar cabeçalho, tentar de novo e transformar 4xx em exceção.
     *
     * @param  array<string, mixed>  $query
     * @param  ?array<string, mixed>  $body
     * @param  array<string, string>  $headers  cabeçalhos desta chamada, além dos padrão
     * @return array<string, mixed>
     *
     * @throws ApiException
     * @throws TransportException
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null, array $headers = []): array
    {
        $resposta = $this->comTentativas($method, $this->url($path, $query), $body, $headers);

        if (! $resposta->successful()) {
            throw ApiException::fromResponse($resposta);
        }

        return $resposta->data;
    }

    /**
     * @param  ?array<string, mixed>  $body
     * @param  array<string, string>  $headers
     *
     * @throws TransportException
     */
    private function comTentativas(string $method, string $url, ?array $body, array $headers = []): Response
    {
        $ultimaFalha = null;
        $limite = $this->repetivel($method, $headers) ? $this->retries : 0;

        for ($tentativa = 0; $tentativa <= $limite; $tentativa++) {
            if ($tentativa > 0) {
                /*
                 * Espera dobrando: 1s, 2s, 4s. Sem ela, "tentar de novo" contra
                 * um 429 é mandar a mesma enxurrada de novo e gastar as três
                 * tentativas no mesmo segundo.
                 */
                sleep(2 ** ($tentativa - 1));
            }

            try {
                $resposta = $this->transport->send($method, $url, $this->cabecalhos($headers), $body);
            } catch (TransportException $e) {
                $ultimaFalha = $e;

                continue;
            }

            if (! $resposta->retryable()) {
                return $resposta;
            }

            $ultimaResposta = $resposta;
        }

        if (isset($ultimaResposta)) {
            /*
             * Esgotou as tentativas contra 429 ou 5xx: devolve a resposta em
             * vez de lançar, para o `request()` transformá-la na `ApiException`
             * com o código que a API mandou. Quem integra precisa distinguir
             * "a cota de requisições estourou" de "o servidor caiu".
             */
            return $ultimaResposta;
        }

        throw $ultimaFalha ?? new TransportException('A requisição falhou sem resposta nem erro.');
    }

    /**
     * Dá para tentar esta chamada de novo sem risco de acontecer duas vezes?
     *
     * `GET` e `DELETE` são seguros por definição, e o `PUT` de contato é um
     * upsert — mandar duas vezes deixa a base no mesmo estado. Sobra o `POST`,
     * e nele o perigo é concreto: um 5xx NÃO diz que o servidor não fez. Ele
     * pode ter aceitado o envio e caído ao responder, e a tentativa seguinte
     * manda o mesmo e-mail para a mesma pessoa.
     *
     * Com `Idempotency-Key` o risco some — a repetição devolve o registro
     * anterior em vez de disparar de novo —, então aí a repetição volta a valer.
     *
     * A decisão é por método e cabeçalho, nunca por caminho: uma rota nova
     * nasce protegida, em vez de entrar numa lista que ninguém lembra de
     * atualizar.
     *
     * @param  array<string, string>  $headers
     */
    private function repetivel(string $method, array $headers): bool
    {
        if (strtoupper($method) !== 'POST') {
            return true;
        }

        foreach ($headers as $nome => $valor) {
            if (strtolower($nome) === 'idempotency-key' && trim($valor) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $extras
     * @return array<string, string>
     */
    private function cabecalhos(array $extras = []): array
    {
        $cabecalhos = [
            'Authorization' => "Bearer {$this->token}",
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',

            /*
             * Identifica o pacote e a versão nos logs do servidor. É o que
             * permite saber, quando um contrato mudar, quantos clientes ainda
             * estão numa versão que a mudança quebraria.
             */
            'User-Agent' => 'wisedata-mail-php/'.self::VERSION.' php/'.PHP_VERSION,
        ];

        if ($this->space !== null && $this->space !== '') {
            $cabecalhos['X-WiseData-Space'] = $this->space;
        }

        /*
         * Os extras vêm por último e NÃO sobrescrevem os de cima: um
         * `Authorization` vindo daqui trocaria a credencial da chamada sem que
         * nada no código do chamador sugerisse isso.
         *
         * A conferência é aqui, e não só no `CurlTransport`, para valer também
         * para quem injeta o próprio transporte — ver `HeaderGuard`.
         */
        return HeaderGuard::validated([...$extras, ...$cabecalhos]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function url(string $path, array $query): string
    {
        $url = rtrim($this->baseUrl, '/').'/v1/'.ltrim($path, '/');

        $filtrada = array_filter($query, static fn (mixed $valor): bool => $valor !== null && $valor !== '');

        return $filtrada === [] ? $url : $url.'?'.http_build_query($filtrada);
    }
}
