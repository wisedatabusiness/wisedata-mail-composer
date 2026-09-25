# wisedata/mail-php

Cliente PHP da API do [WiseData Mail](https://wisedatamail.com). Sincroniza
contatos e dispara e-mail transacional — em Laravel, trocando uma linha do
`.env`.

Sem dependência de produção: só `ext-curl` e `ext-json`, que já vêm no PHP.

```bash
composer require wisedata/mail-php
```

## Uso

```php
use WiseData\Mail\Client;

$mail = new Client('wdm_abc_def');

$mail->contacts()->upsert([
    'external_id' => 'usr_9',          // o id desta pessoa NO SEU sistema
    'email'       => 'ana@exemplo.com',
    'first_name'  => 'Ana',
    'fields'      => ['plano_atual' => 'pro'],
    'lists'       => [3],
]);
```

`upsert` cria ou corrige: mandar a mesma pessoa duas vezes deixa a base no mesmo
estado. É por isso que ele pode ser chamado de dentro de um job que reprocessa
fila depois de uma queda.

### `external_id` é o que evita duplicado

O e-mail muda. Sem um identificador estável, o aviso de "fulano trocou de
e-mail" chega sem dizer qual fulano, e nasce um segundo contato — com o antigo
ainda recebendo.

Mandando `external_id`, a troca de endereço corrige o contato que já existe.
Quem não tem um identificador próprio pode omitir: aí o e-mail é a chave, como
sempre foi.

### O resto dos contatos

```php
$mail->contacts()->find('usr_9');                       // por external_id
$mail->contacts()->findByEmail('ana@exemplo.com');      // null se não achar
$mail->contacts()->list(page: 1, perPage: 25);
$mail->contacts()->delete('usr_9');
$mail->contacts()->deleteByEmail('ana@exemplo.com');    // para quem não tem external_id
```

**`lists` no `upsert` DEFINE o conjunto**: ausente mantém as listas do contato,
`lists: []` tira de todas. Sem a distinção, corrigir um telefone apagaria a
segmentação dele.

Para ACRESCENTAR sem conhecer as outras listas — o caso de quem administra uma
lista entre várias:

```php
$mail->contacts()->addToLists('usr_9', [3, 7]);
$mail->contacts()->removeFromLists('usr_9', [3]);
```

Com o `upsert` seria preciso ler antes de escrever, e a corrida apagaria o que
outro produto vinculou no intervalo.

### Campos personalizados

A gravação **recusa** campo que não existe na conta, com o nome dele no erro.
Descubra os nomes uma vez, no boot do seu serviço:

```php
$mail->catalog()->fields();   // [['key' => 'plano_atual', 'type' => 'text', ...], ...]
$mail->catalog()->lists();    // [['id' => 3, 'name' => 'Clientes'], ...]
```

Campo personalizado fala por `key`; lista fala por `id` — a pessoa renomeia a
lista na tela sem avisar ninguém, e o nome deixaria de casar em silêncio.

### Espaços de envio

Se a sua chave alcança mais de um espaço, diga qual em cada chamada:

```php
$marcaB = $mail->forSpace('marca-b');
$marcaB->contacts()->upsert([...]);
```

`forSpace()` devolve outro cliente — o original continua apontando para onde
estava. Com vários espaços vinculados e nenhum escolhido, a API **recusa** em
vez de adivinhar: escolher errado gravaria o cliente de uma marca na base de
outra, em silêncio.

## Enviando e-mail

```php
$mail->emails()->send([
    'to' => 'ana@exemplo.com',
    'to_name' => 'Ana',
    'subject' => 'Seu código de acesso',
    'html' => '<p>Seu código é <strong>123456</strong>.</p>',
    'text' => 'Seu código é 123456.',
], idempotencyKey: 'codigo-usr9-2026-09-24');
```

A resposta traz `id` e `status`. **`suppressed` não é erro**: a chamada foi
aceita, e a mensagem não sai porque aquela pessoa devolveu, reclamou ou foi
suprimida na conta. Nada é cobrado da cota.

**Um destinatário por mensagem.** Transacional é de uma pessoa — o recibo é dela,
o código é dela. Para falar com um grupo existe a campanha.

### Modelo salvo no Mail

O texto pode viver no painel em vez de no seu código, endereçado por uma chave:

```php
$mail->emails()->send([
    'to' => 'ana@exemplo.com',
    'template' => 'boas-vindas',
    'variables' => ['nome' => 'Ana', 'plano' => 'Crescimento'],
]);
```

Assim, corrigir uma frase do e-mail é uma edição na tela — não um release do seu
produto.

**As variáveis são as que o texto do modelo usa**, detectadas dele. Nome que o
modelo não usa (`unknown_variable`, e a resposta traz os aceitos) e nome que ele
usa e não veio (`missing_variable`) são recusados com 422 — o e-mail nunca sai
com um buraco no lugar do dado.

`template` não se combina com `subject`/`html`/`text`: a chamada escolhe uma das
duas formas.

### A chave de idempotência

Mandar a mesma chave de novo devolve o mesmo registro, sem reenviar — é o que
permite reprocessar uma fila depois de uma queda sem duplicar e-mail. Derive o
valor do EVENTO que originou a mensagem (`pedido-1234-confirmado`), nunca de um
aleatório: um `uuid()` gerado na hora da chamada muda a cada tentativa e não
protege de nada.

## Laravel

O provider é descoberto sozinho. Basta o `.env`:

```dotenv
WISEDATA_MAIL_TOKEN=wdm_abc_def
# WISEDATA_MAIL_SPACE=marca-b
```

```php
app(\WiseData\Mail\Client::class)->contacts()->upsert([...]);
```

Para ajustar tudo: `php artisan vendor:publish --tag=wisedata-mail-config`.

### `MAIL_MAILER=wisedatamail`

```dotenv
MAIL_MAILER=wisedatamail
WISEDATA_MAIL_TOKEN=wdm_abc_def
```

É só isso. **Não é preciso tocar em `config/mail.php`** — o pacote registra o
mailer sozinho. Todo `Mailable`, toda `Notification` e todo `Mail::to()` que já
existem passam a sair pelo WiseData Mail sem uma linha alterada.

O remetente é o **remetente verificado** da conta no WiseData Mail, não o
`MAIL_FROM_ADDRESS` da aplicação: um endereço não verificado sai sem DKIM
alinhado, e o provedor de quem recebe trata isso como falsificação.

Um segundo mailer, para outro espaço ou outro tipo, aí sim vai no
`config/mail.php`:

```php
'wisedatamail_marketing' => [
    'transport' => 'wisedatamail',
    'space' => 'marca-b',
    'message_type' => 'marketing',
],
```

### Anexo ainda não

Mensagem com anexo é **recusada**, e nada é enviado:

> O WiseData Mail ainda não envia anexo, e esta mensagem tem 1. NADA foi enviado
> — entregar o e-mail sem o anexo esconderia o problema até o destinatário
> reclamar. Publique o arquivo e mande o link, ou use outro mailer para esta
> mensagem.

Vale também para imagem embutida com `embed()`: sem a parte, o HTML aponta para
um `cid:` que não existe e a imagem vira ícone quebrado.

O mesmo acontece com vários destinatários ou cópia — um envio por pessoa.

### Tipo da mensagem

Quem decide é a **chave**, pelo padrão escolhido quando ela foi emitida. É por
isso que migrar não pede mudança em `Mailable` nenhum.

Para uma mensagem específica:

```php
public function headers(): Headers
{
    return new Headers(text: ['X-WiseData-Message-Type' => 'marketing']);
}
```

O cabeçalho é lido e removido — não chega a quem recebe. Os demais `X-...` da
sua aplicação são encaminhados.

### Repetição e e-mail duplicado

O mailer usa `retries: 0` de propósito. Repetir um `POST` de envio é arriscar
mandar duas vezes — o servidor pode ter aceitado e caído ao responder. Quem
repete é a fila do Laravel, com o backoff dela. Aumentar esse número põe duas
camadas tentando a mesma coisa.

### Fora do Laravel

```bash
composer require symfony/mailer
```

```php
use Symfony\Component\Mailer\Mailer;
use WiseData\Mail\Mailer\WiseDataMailTransport;

$mailer = new Mailer(new WiseDataMailTransport(new Client('wdm_abc_def')));
```

## Erros

```php
use WiseData\Mail\Exception\ApiException;
use WiseData\Mail\Exception\TransportException;

try {
    $mail->contacts()->upsert([...]);
} catch (ApiException $e) {
    // A API respondeu não.
    $e->error;    // 'unknown_field', 'insufficient_scope', 'space_required', ...
    $e->status;   // 401, 403, 404, 422
    $e->extra;    // 'field', 'required_scope', 'upgrade_to', conforme o caso
} catch (TransportException $e) {
    // A requisição não chegou: DNS, TLS, tempo esgotado.
}
```

**Escreva o seu `if` contra `$e->error`, nunca contra a mensagem.** O código é
estável; a mensagem sai no idioma do `Accept-Language` e pode ser reescrita a
qualquer momento.

Os principais:

| `error` | O que fazer |
|---|---|
| `invalid_api_key` | conferir o token e se ele não foi revogado |
| `insufficient_scope` | criar uma chave com o escopo em `extra['required_scope']` |
| `space_required` | informar o espaço com `forSpace()` |
| `space_not_allowed` | o espaço não pertence à chave |
| `plan_feature_required` | o plano da conta não inclui a API |
| `subscription_locked` | a assinatura não está ativa |
| `account_read_only` | a conta está em consulta; escrita bloqueada |
| `unknown_field` | o campo em `extra['field']` não existe — ver `catalog()->fields()` |
| `send_quota_exceeded` | a cota do ciclo acabou; `extra['upgrade_to']` diz o plano que resolve |
| `sending_unavailable` | a conta ainda não está pronta para enviar — repetir mais tarde |
| `sending_suspended` | o envio da conta foi suspenso; só o suporte libera |
| `template_not_found` | nenhum modelo ATIVO com a chave em `extra['template']` |
| `unknown_variable` | o modelo não usa a variável; os aceitos vêm em `extra['accepted_variables']` |
| `missing_variable` | o modelo usa `extra['variable']` e ela não foi enviada |
| `rate_limited` | passou do limite de requisições; esperar e repetir |

No mailer, tudo isso chega como `WiseDataMailTransportException`, que é uma
`TransportException` do Symfony — o job falha e vai para `failed_jobs` como
qualquer outra falha de e-mail. O código continua em `$e->error`.

## Tentativas

429, 5xx e falha de rede são repetidos duas vezes por padrão, com espera
dobrando. 4xx de conteúdo (422, 404) **não** são: repetir um corpo inválido
devolve o mesmo erro e gasta o limite da chave.

Dentro de um job que já tem repetição própria, desligue com `retries: 0` para as
duas não se multiplicarem.

## Recebendo os eventos (webhook)

O caminho de volta não passa por este pacote: quem entrega é o Mail, num `POST`
para o endereço que você cadastra em *Configurações → Webhooks*. O contrato
completo — todos os tipos de evento e um exemplo de corpo para cada um — está na
documentação da API, em <https://wisedatamail.com/docs/api>; o que importa aqui é
conferir a assinatura antes de confiar no corpo.

```php
$corpo = file_get_contents('php://input');
$timestamp = $_SERVER['HTTP_X_WISEDATA_TIMESTAMP'] ?? '';

$esperada = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$corpo, $segredo);

// `hash_equals`, nunca `===`: comparação comum vaza o tamanho do prefixo certo.
// A janela de cinco minutos é o que impede o reenvio de um par capturado.
if (abs(time() - (int) $timestamp) > 300
    || ! hash_equals($esperada, $_SERVER['HTTP_X_WISEDATA_SIGNATURE'] ?? '')) {
    http_response_code(401);
    exit;
}
```

Três coisas que dão errado e não parecem defeito:

- **Assine o corpo cru.** Decodificar e recodificar o JSON muda a ordem das
  chaves e o escape, e a conferência deixa de bater. No Laravel, use
  `$request->getContent()`, não `$request->all()`.
- **Responda 2xx antes de processar.** O tempo limite é de dez segundos, e o
  lote é reenviado a cada resposta que não seja 2xx.
- **Deduplique pelo `X-WiseData-Delivery`.** O reenvio leva o mesmo id e o mesmo
  corpo.

A rota precisa ficar **fora do CSRF** (`$except` do `VerifyCsrfToken`, ou em
`routes/api.php`): quem chama é um servidor, sem sessão e sem token.

## Outra biblioteca HTTP

O padrão é cURL para o pacote não impor escolha a ninguém. Quem já usa Guzzle ou
o cliente do Laravel implementa `WiseData\Mail\Contracts\Transport` e injeta:

```php
new Client(token: '...', transport: new MeuTransporte);
```

## Testes

```bash
composer install
vendor/bin/phpunit
```

Não há chamada de rede na suíte — o `FakeTransport` devolve respostas
programadas.

## Licença

MIT.
