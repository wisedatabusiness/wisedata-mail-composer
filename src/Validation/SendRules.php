<?php

declare(strict_types=1);

namespace WiseData\Mail\Validation;

/**
 * As regras de `POST /v1/emails` que o pacote confere antes da rede.
 *
 * Espelho de `SendEmailRequest` e `SafeEmailHeaderRule` da API. Existe por um
 * incidente: o transport mandava os metadados do `Envelope` do Laravel como
 * `X-Metadata-user_id`, a API recusava o `_` com 422, e cada lembrete falhava em
 * produção — enquanto o teste da aplicação, com a fake, passava. Com as regras
 * aqui, o transport recusa sem gastar rede e a fake recusa igual à API, então o
 * erro aparece no CI de quem consome.
 *
 * Os números precisam andar junto com `config/mail-sending.php` da API.
 */
final class SendRules
{
    public const MAX_TAGS = 10;

    public const MAX_TAG_LENGTH = 128;

    public const MAX_METADATA = 10;

    public const MAX_METADATA_KEY_LENGTH = 40;

    public const MAX_METADATA_VALUE_LENGTH = 255;

    public const MAX_HEADERS = 10;

    public const MAX_HEADER_NAME_LENGTH = 100;

    public const MAX_HEADER_VALUE_LENGTH = 255;

    /** Em minúsculas: nome de cabeçalho não diferencia maiúsculas. */
    private const RESERVED_HEADERS = [
        'received', 'dkim-signature', 'message-id', 'mime-version',
        'date', 'sender', 'return-path',
        'content-type', 'content-transfer-encoding',
        'to', 'from', 'subject', 'reply-to', 'cc', 'bcc',
    ];

    public const RESERVED_HEADER_PREFIX = 'x-ses-';

    /**
     * O cancelamento em um clique do integrador (RFC 2369 e RFC 8058). Só em
     * `marketing`: no transacional o botão tiraria alguém de uma lista por causa
     * de um recibo.
     */
    public const LIST_UNSUBSCRIBE = 'List-Unsubscribe';

    public const LIST_UNSUBSCRIBE_POST = 'List-Unsubscribe-Post';

    public const ONE_CLICK = 'List-Unsubscribe=One-Click';

    /** O teto do `POST /v1/emails/batch`. */
    public const MAX_BATCH = 100;

    public static function isListUnsubscribeHeader(string $nome): bool
    {
        return in_array(strtolower(trim($nome)), ['list-unsubscribe', 'list-unsubscribe-post'], true);
    }

    /**
     * As violações do corpo, no formato de `errors` do 422 da API.
     *
     * As chaves seguem as da API (`header_names.0`, `metadata_keys.0`,
     * `tags.1`, `metadata.user_id`), para o mesmo erro ter o mesmo nome dos dois
     * lados.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, list<string>>
     */
    public static function violations(array $payload): array
    {
        return [
            ...self::headers($payload['headers'] ?? null),
            ...self::listUnsubscribe($payload['headers'] ?? null, $payload['message_type'] ?? null),
            ...self::tags($payload['tags'] ?? null),
            ...self::metadata($payload['metadata'] ?? null),
        ];
    }

    /**
     * As violações de um lote, com o caminho de cada mensagem (`emails.3.tags.1`).
     *
     * @param  list<mixed>  $emails
     * @return array<string, list<string>>
     */
    public static function batchViolations(array $emails): array
    {
        if ($emails === [] || count($emails) > self::MAX_BATCH) {
            return ['emails' => ['O lote leva de 1 a '.self::MAX_BATCH.' mensagens.']];
        }

        $erros = [];

        foreach ($emails as $posicao => $email) {
            if (! is_array($email)) {
                $erros["emails.{$posicao}"] = ['Cada mensagem do lote é um objeto.'];

                continue;
            }

            if (array_key_exists('attachments', $email)) {
                $erros["emails.{$posicao}.attachments"] = ['Anexo não é aceito no lote. Envie essa mensagem por emails()->send().'];
            }

            /** @var array<string, mixed> $email */
            foreach (self::violations($email) as $campo => $mensagens) {
                $erros["emails.{$posicao}.{$campo}"] = $mensagens;
            }
        }

        return $erros;
    }

    /**
     * O par só em marketing e bem formado. O tipo ausente é decidido pela chave na
     * API, que o pacote não conhece — então aqui só o `transactional` declarado recusa.
     *
     * @return array<string, list<string>>
     */
    private static function listUnsubscribe(mixed $cabecalhos, mixed $tipo): array
    {
        if (! is_array($cabecalhos)) {
            return [];
        }

        $lista = null;
        $post = null;

        foreach ($cabecalhos as $nome => $valor) {
            $minusculo = strtolower(trim((string) $nome));

            if ($minusculo === 'list-unsubscribe' && is_string($valor)) {
                $lista = $valor;
            }

            if ($minusculo === 'list-unsubscribe-post' && is_string($valor)) {
                $post = $valor;
            }
        }

        if ($lista === null && $post === null) {
            return [];
        }

        if ($tipo === 'transactional') {
            return ['headers' => ['List-Unsubscribe só pode ir em mensagem de marketing. Envie com message_type "marketing" ou tire o cabeçalho.']];
        }

        $erros = [];
        $enderecos = $lista === null ? null : self::enderecosDeDescadastro($lista);

        if ($lista !== null && $enderecos === null) {
            $erros[] = 'List-Unsubscribe precisa ser uma ou mais entradas <https://...> ou <mailto:...>, separadas por vírgula.';
        }

        if ($post !== null && trim($post) !== self::ONE_CLICK) {
            $erros[] = 'List-Unsubscribe-Post só aceita o valor "'.self::ONE_CLICK.'".';
        }

        $temHttps = array_filter($enderecos ?? [], static fn (string $e): bool => str_starts_with(strtolower($e), 'https://')) !== [];

        if ($post !== null && ! $temHttps) {
            $erros[] = 'List-Unsubscribe-Post exige um endereço https em List-Unsubscribe: é nele que o clique único faz o POST.';
        }

        return $erros === [] ? [] : ['headers' => $erros];
    }

    /**
     * @return ?list<string> `null` quando o formato não fecha
     */
    private static function enderecosDeDescadastro(string $valor): ?array
    {
        $enderecos = [];

        foreach (explode(',', $valor) as $parte) {
            if (preg_match('/^\s*<([^<>\s]+)>\s*$/', $parte, $achado) !== 1) {
                return null;
            }

            $esquema = strtolower((string) parse_url($achado[1], PHP_URL_SCHEME));
            $valido = ($esquema === 'https' && filter_var($achado[1], FILTER_VALIDATE_URL) !== false)
                || ($esquema === 'mailto' && strlen($achado[1]) > strlen('mailto:'));

            if (! $valido) {
                return null;
            }

            $enderecos[] = $achado[1];
        }

        return $enderecos === [] ? null : $enderecos;
    }

    /** `null` quando o nome passa. */
    public static function headerNameViolation(string $nome): ?string
    {
        $minusculo = strtolower(trim($nome));

        if (self::isListUnsubscribeHeader($minusculo)) {
            return null;
        }

        if (in_array($minusculo, self::RESERVED_HEADERS, true) || str_starts_with($minusculo, self::RESERVED_HEADER_PREFIX)) {
            return "O cabeçalho \"{$nome}\" é reservado e não pode ser enviado.";
        }

        if (strlen($nome) > self::MAX_HEADER_NAME_LENGTH || preg_match('/^[A-Za-z0-9-]+$/', $nome) !== 1) {
            return "Nome de cabeçalho inválido: \"{$nome}\". Use só letras, números e hífen (o \"_\" é recusado), "
                .'até '.self::MAX_HEADER_NAME_LENGTH.' caracteres.';
        }

        return null;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function headers(mixed $cabecalhos): array
    {
        if ($cabecalhos === null) {
            return [];
        }

        if (! is_array($cabecalhos)) {
            return ['headers' => ['"headers" precisa ser um objeto nome → valor.']];
        }

        $erros = [];

        if (count($cabecalhos) > self::MAX_HEADERS) {
            $erros['headers'] = ['No máximo '.self::MAX_HEADERS.' cabeçalhos personalizados.'];
        }

        foreach (array_keys($cabecalhos) as $posicao => $nome) {
            $violacao = self::headerNameViolation((string) $nome);

            if ($violacao !== null) {
                $erros["header_names.{$posicao}"] = [$violacao];
            }

            $valor = $cabecalhos[$nome];

            if (! is_string($valor) || strlen($valor) > self::MAX_HEADER_VALUE_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $valor) === 1) {
                $erros["headers.{$nome}"] = [
                    "O valor do cabeçalho \"{$nome}\" precisa ser texto de até ".self::MAX_HEADER_VALUE_LENGTH
                    .' caracteres, sem quebra de linha nem caractere de controle.',
                ];
            }
        }

        return $erros;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function tags(mixed $tags): array
    {
        if ($tags === null) {
            return [];
        }

        if (! is_array($tags) || ! array_is_list($tags)) {
            return ['tags' => ['"tags" precisa ser uma lista de textos.']];
        }

        $erros = [];

        if (count($tags) > self::MAX_TAGS) {
            $erros['tags'] = ['No máximo '.self::MAX_TAGS.' tags.'];
        }

        $vistas = [];

        foreach ($tags as $posicao => $tag) {
            if (! is_string($tag) || $tag === '' || mb_strlen($tag) > self::MAX_TAG_LENGTH || preg_match('/\p{Cc}/u', $tag) !== 0) {
                $erros["tags.{$posicao}"] = ['Cada tag é um texto de 1 a '.self::MAX_TAG_LENGTH.' caracteres, sem caractere de controle.'];

                continue;
            }

            if (isset($vistas[$tag])) {
                $erros["tags.{$posicao}"] = ["A tag \"{$tag}\" está repetida."];
            }

            $vistas[$tag] = true;
        }

        return $erros;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function metadata(mixed $metadados): array
    {
        if ($metadados === null) {
            return [];
        }

        if (! is_array($metadados)) {
            return ['metadata' => ['"metadata" precisa ser um objeto chave → valor.']];
        }

        $erros = [];

        if (count($metadados) > self::MAX_METADATA) {
            $erros['metadata'] = ['No máximo '.self::MAX_METADATA.' metadados.'];
        }

        $posicao = -1;

        foreach ($metadados as $chave => $valor) {
            $posicao++;
            $chave = (string) $chave;

            if (strlen($chave) > self::MAX_METADATA_KEY_LENGTH || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $chave) !== 1) {
                $erros["metadata_keys.{$posicao}"] = [
                    "Chave de metadado inválida: \"{$chave}\". Use letras, números, \"_\", \".\" e \"-\", começando por "
                    .'letra ou "_", até '.self::MAX_METADATA_KEY_LENGTH.' caracteres.',
                ];
            }

            if (! is_scalar($valor)) {
                $erros["metadata.{$chave}"] = ['O valor de um metadado precisa ser texto, número ou booleano.'];

                continue;
            }

            if (mb_strlen(self::asText($valor)) > self::MAX_METADATA_VALUE_LENGTH) {
                $erros["metadata.{$chave}"] = ['O valor de um metadado tem até '.self::MAX_METADATA_VALUE_LENGTH.' caracteres.'];
            }
        }

        return $erros;
    }

    /** Como a API guarda o valor: `true` vira `"true"`, não `"1"`. */
    public static function asText(bool|float|int|string $valor): string
    {
        if (is_bool($valor)) {
            return $valor ? 'true' : 'false';
        }

        return (string) $valor;
    }
}
