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

### Anexo pela API crua

```php
$mail->emails()->send([
    'to' => 'ana@exemplo.com',
    'subject' => 'Seu relatório de setembro',
    'html' => '<p>Segue em anexo.</p>',
    'attachments' => [[
        'filename' => 'relatorio-setembro.pdf',
        'content' => base64_encode(file_get_contents($caminho)),
        'content_type' => 'application/pdf',
    ]],
]);
```

`filename` e `content` (em base64) são obrigatórios; `content_type` é opcional —
sem ele o tipo é deduzido da extensão, então informe-o quando o nome não tiver
uma.

Para imagem embutida, `disposition` e `content_id`, e o HTML referencia por
`cid:`:

```php
'attachments' => [[
    'filename' => 'logo.png',
    'content' => base64_encode($png),
    'content_type' => 'image/png',
    'disposition' => 'inline',
    'content_id' => 'logo123',
]],
// no HTML: <img src="cid:logo123">
```

Dez megabytes somando tudo, dez arquivos. Extensão de executável ou script é
recusada com `422` nomeando o arquivo — a lista é do provedor de envio.

**Anexo combina com `template`**, ao contrário de `subject`/`html`/`text`: o
modelo guarda o texto, a chamada manda o arquivo.

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

### O remetente

O mailer manda o endereço do `from` da mensagem — no Laravel, o
`MAIL_FROM_ADDRESS`, ou o `from()` do `Mailable` — e a API o usa. **Ele precisa
ser um remetente verificado** da conta no WiseData Mail (*Configurações →
Remetentes*); se não for, a API recusa com `422` e nada sai. Um endereço não
verificado sairia sem DKIM alinhado, e o provedor de quem recebe trataria como
falsificação.

O **nome** exibido não viaja: é o do cadastro do remetente no WiseData Mail.

Duas saídas quando o `MAIL_FROM_ADDRESS` da aplicação não é (ou não pode ser)
um remetente verificado:

```dotenv
# 1. Deixar a conta decidir: o `from` não vai e sai o remetente padrão da conta.
WISEDATA_MAIL_USE_ACCOUNT_SENDER=true
```

```php
// 2. Um remetente só para este mailer, em config/mail.php (recurso do Laravel).
'wisedatamail' => [
    'transport' => 'wisedatamail',
    'from' => ['address' => 'avisos@seudominio.com', 'name' => 'Seu Produto'],
],
```

`use_account_sender` também vale por mailer, no `config/mail.php`.

Um segundo mailer, para outro espaço ou outro tipo, aí sim vai no
`config/mail.php`:

```php
'wisedatamail_marketing' => [
    'transport' => 'wisedatamail',
    'space' => 'marca-b',
    'message_type' => 'marketing',
],
```

### Anexo

Funciona como em qualquer mailer do Laravel — nada a configurar:

```php
Mail::to($cliente)->send(new RelatorioMensal($pdf));

// dentro do Mailable
public function attachments(): array
{
    return [Attachment::fromPath($caminho)->as('relatorio-setembro.pdf')];
}
```

`embed()` também: a imagem vira anexo embutido e o `cid:` do HTML aponta para
ela, que é o logotipo no cabeçalho.

**Dez megabytes somando tudo, dez arquivos por mensagem.** O pacote recusa antes
de subir, para você não gastar a rede num 422 previsível.

**Nome de arquivo é obrigatório.** O Symfony aceita parte sem nome; nós não.
Inventar `anexo-1.bin` entregaria ao destinatário um nome que ninguém escolheu.

**Quem decide quais extensões passam é o servidor**, não o pacote — a lista é do
provedor de envio e muda sem aviso. Executável e script são recusados com `422`
dizendo qual arquivo.

Vários destinatários ou cópia continuam recusados: um envio por pessoa.

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

### Tags e metadados do Laravel

```php
public function envelope(): Envelope
{
    return new Envelope(
        subject: 'Suas contas vencem amanhã',
        tags: ['due-reminders'],
        metadata: ['user_id' => $this->user->id, 'reminder_type' => 'digest'],
    );
}
```

O `Envelope` vira `TagHeader`/`MetadataHeader` no Symfony, e o transport os
manda nos campos `tags` e `metadata` da chamada — **nunca como cabeçalho do
e-mail**. É o mesmo que os bridges oficiais do Symfony fazem com Postmark,
SendGrid e Mailgun. Eles ficam guardados com a mensagem e voltam em todo evento
de webhook dela (`$evento->tags`, `$evento->metadata`), para você ligar a
devolução ou a entrega ao registro de origem.

| Regra | Teto |
|---|---|
| tags | 10, até 128 caracteres cada; repetida sai uma vez só |
| metadados | 10 chaves |
| chave | até 40; letras, números, `_`, `.` e `-`, começando por letra ou `_` |
| valor | texto, número ou booleano, até 255; volta no webhook sempre como texto |

Fora disso, o transport recusa **antes da rede** com
`WiseDataMailTransportException` (`error: validation_failed`) dizendo o campo.

**Não ponha dado pessoal aqui** (e-mail, nome, CPF): o metadado fica no banco do
Mail e viaja até o seu endpoint de webhook. Use o id interno.

> Por que existe: até a 1.1.2 o transport mandava os metadados como cabeçalho
> `X-Metadata-user_id`, que a API recusa (o `_` não vale em nome de cabeçalho)
> — e que, se passasse, mostraria o `user_id` a quem recebe o e-mail.

### Cabeçalhos personalizados

Todo `X-...` que a sua aplicação puser na mensagem vai em `headers`, conferido
localmente com a regra da API:

- nome só com **letras, números e hífen** (`X-Pedido-Id` passa; `X-Pedido_Id`
  é recusado);
- até 10 cabeçalhos, valor até 255 caracteres e sem quebra de linha;
- a família **`X-SES-`** é reservada da infraestrutura de envio: o transport a
  descarta, e a API a recusa se chegar pela chamada crua. Os nomes que o e-mail
  monta sozinho (`From`, `To`, `Message-ID`, `List-Unsubscribe`, ...) também são
  reservados.

### Repetição e e-mail duplicado

O mailer usa `retries: 0` de propósito. Repetir um `POST` de envio é arriscar
mandar duas vezes — o servidor pode ter aceitado e caído ao responder. Quem
repete é a fila do Laravel, com o backoff dela. Aumentar esse número põe duas
camadas tentando a mesma coisa.

### Conferir o envio depois do deploy

```bash
php artisan wisedata-mail:test voce@exemplo.com
php artisan wisedata-mail:test voce@exemplo.com --mailer=wisedatamail_marketing
```

Envia **na hora** (sem fila) pelo mesmo caminho de um `Mailable` — token,
espaço, remetente — e mostra:

```text
Remetente: avisos@seudominio.com (precisa ser um remetente verificado no WiseData Mail)
Enviando para voce@exemplo.com pelo mailer [wisedatamail]...
Aceito pelo WiseData Mail.
Id da mensagem: 8821
```

ou o código do erro da API (`invalid_api_key`, `validation_failed` com o campo
recusado, ...), com saída `1`. Funciona mesmo com outro mailer como padrão: o
`MAIL_MAILER` não precisa ser `wisedatamail`.

### Testes da aplicação

```php
use WiseData\Mail\Laravel\Facades\WiseDataMail;

public function test_o_recibo_sai_pelo_wisedata_mail(): void
{
    $mail = WiseDataMail::fake();

    $this->post('/pedidos/1234/pagar');

    $mail->assertSentTo('ana@exemplo.com', fn (array $corpo) => $corpo['subject'] === 'Seu recibo');
    $mail->assertSentCount(1);
}
```

`WiseDataMail::fake()` troca só a **fronteira HTTP**: o mailer `wisedatamail` e o
`Client` do contêiner continuam os reais, então o teste prova o corpo que sairia
para a API — `to`, `subject`, `html`, `text`, `from`, `attachments`, `headers`,
`tags`, `metadata`.
Cada envio é aceito com um `id` sequencial (`1`, `2`, ...). Vale também para o
mailer que já tinha sido resolvido antes do `fake()`, e não exige token.

**A fake aplica as regras da API** (nome de cabeçalho, limites de `tags` e
`metadata`) e devolve o mesmo `422 validation_failed` que ela daria, com
`errors`. Assim um `Mailable` que a API recusaria quebra no seu CI, e não em
produção.

| Método | |
|---|---|
| `assertSent(?callable)` | houve envio (que satisfaz o filtro) |
| `assertSentTo($email, ?callable)` / `assertNotSentTo($email)` | por destinatário |
| `assertSentCount($n)` / `assertNothingSent()` | contagem |
| `failWith($error, $status = 422)` | as chamadas seguintes são recusadas como a API recusaria |
| `sent(?callable)` / `requests()` | os corpos aceitos / todas as chamadas, inclusive de contato |

`Mail::fake()` do Laravel continua servindo para afirmar **qual `Mailable`** foi
enviado; o `WiseDataMail::fake()` serve para afirmar **o que chega à API**.

O mesmo `WiseDataMail` é a facade do `Client`: `WiseDataMail::contacts()->upsert([...])`.

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
| `validation_failed` | o corpo foi recusado na validação (422); os campos e as mensagens estão em `extra['errors']`. O caso mais comum é `from` que não é remetente verificado. A API devolve `error: validation_failed` junto com `message` e `errors`; com uma API antiga, que respondia sem `error`, o pacote dá o mesmo código |

No mailer, tudo isso chega como `WiseDataMailTransportException`, que é uma
`TransportException` do Symfony — o job falha e vai para `failed_jobs` como
qualquer outra falha de e-mail. O código continua em `$e->error`.

## Tentativas

429, 5xx e falha de rede são repetidos duas vezes por padrão, com espera
dobrando. 4xx de conteúdo (422, 404) **não** são: repetir um corpo inválido
devolve o mesmo erro e gasta o limite da chave.

Dentro de um job que já tem repetição própria, desligue com `retries: 0` para as
duas não se multiplicarem.

## Homologação

Não há nada a configurar neste pacote: quem decide é a **chave**.

Em *Configurações → Integrações*, marque **"Chave de homologação"** ao criar. O
mesmo código que roda em produção, apontado para essa chave, tem a chamada
aceita por inteiro — mas **nenhum e-mail sai**, **nenhuma cota é consumida** e a
mensagem aparece em *Configurações → Caixa de teste*, onde dá para ler o
conteúdo montado, conferir os anexos e simular os desfechos.

```dotenv
# .env do ambiente de homologação — só a chave muda
WISEDATA_MAIL_TOKEN=wdm_hml_...
```

A marca está na chave, e não num parâmetro de `enviar()`, exatamente para que
uma homologação **não consiga** alcançar um cliente real por esquecimento.

Simular um desfecho na caixa dispara o webhook de verdade, com a mesma
assinatura — é assim que se testa o receptor sem esperar uma devolução real
acontecer.

## Recebendo os eventos (webhook)

O caminho de volta: o WiseData Mail faz um `POST` no endereço cadastrado em
*Configurações → Webhooks* (o cadastro é só no painel; a API não tem rota para
isso). Na criação, a tela mostra **uma vez** o segredo de assinatura.

### No Laravel, pronto

```dotenv
WISEDATA_MAIL_WEBHOOK_SECRET=o-segredo-exibido-na-criacao
WISEDATA_MAIL_WEBHOOK_PATH=webhooks/wisedata-mail
```

Com o `PATH` preenchido o pacote registra `POST /webhooks/wisedata-mail` (nome
`wisedata-mail.webhook`), **fora** dos grupos `web` e `api` — sem CSRF, sessão
nem `throttle`. Vazio, nenhuma rota é aberta. Cadastre no painel a URL completa
(`https://seuapp.com/webhooks/wisedata-mail`).

O receptor:

1. confere a assinatura e a janela de cinco minutos — `401` se não bater, `500`
   se o segredo não estiver configurado (a API reenvia, e o lote é processado
   quando o segredo for preenchido);
2. responde `204` ao ping do botão *Testar*, sem disparar nada;
3. **deduplica pelo `X-WiseData-Delivery`**, num `Cache::add` atômico guardado
   por um dia — a API reenvia um lote por até doze horas;
4. descarta eventos simulados na caixa de homologação (`is_test`), que chegam ao
   mesmo endereço dos reais — ligue `WISEDATA_MAIL_WEBHOOK_ACCEPT_TEST_EVENTS=true`
   só no ambiente de homologação;
5. dispara um evento Laravel por item e responde `204`.

A aplicação só escuta:

```php
use WiseData\Mail\Laravel\Events\WiseDataMailBounced;

final class SuprimirEnderecoDevolvido implements ShouldQueue
{
    public function handle(WiseDataMailBounced $e): void
    {
        $e->event->email;                 // quem
        $e->event->messageId;             // o id devolvido no envio
        $e->event->bounceClassification;  // 'invalid_address', 'mailbox_full', ...
    }
}
```

| `type` | Evento Laravel |
|---|---|
| `delivered` | `WiseDataMailDelivered` |
| `bounce` | `WiseDataMailBounced` |
| `dropped` | `WiseDataMailDropped` |
| `suppressed` | `WiseDataMailSuppressed` |
| `rendering_failure` | `WiseDataMailRenderingFailed` |
| `spamreport` | `WiseDataMailSpamReported` |
| `open` | `WiseDataMailOpened` |
| `click` | `WiseDataMailClicked` |
| `unsubscribe` | `WiseDataMailUnsubscribed` |
| `resubscribe` | `WiseDataMailResubscribed` |
| qualquer outro | `WiseDataMailUnknownEvent` — um tipo novo da API não some |

Todos ficam em `WiseData\Mail\Laravel\Events` e carregam `$event` (um
`WiseData\Mail\Webhook\WebhookEvent`) e `$deliveryId`.

- **Listener demorado implementa `ShouldQueue`.** A API espera o `2xx` por dez
  segundos; passar disso conta como falha e o lote volta.
- **Listener idempotente por `$e->event->id`.** Se um listener lançar exceção,
  o receptor libera o lote e devolve erro, a API reenvia, e os eventos que já
  tinham sido tratados chegam de novo.
- **Correlacionar com o envio:** o `id` que o mailer põe em
  `SentMessage::getMessageId()` é o `message_id` dos eventos. Guarde-o ao lado do
  seu registro.

Prefere a rota no seu arquivo de rotas? Deixe o `PATH` vazio e:

```php
use WiseData\Mail\Laravel\Webhook\VerifyWebhookSignature;
use WiseData\Mail\Laravel\Webhook\WebhookController;

Route::post('/webhooks/wisedata-mail', WebhookController::class)
    ->middleware(VerifyWebhookSignature::class)
    ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);
```

| Variável | Padrão | |
|---|---|---|
| `WISEDATA_MAIL_WEBHOOK_SECRET` | — | o segredo de assinatura; sem ele tudo é recusado |
| `WISEDATA_MAIL_WEBHOOK_PATH` | vazio | liga a rota pronta |
| `WISEDATA_MAIL_WEBHOOK_TOLERANCE` | `300` | janela, em segundos |
| `WISEDATA_MAIL_WEBHOOK_DEDUPE_TTL` | `86400` | por quanto tempo um `X-WiseData-Delivery` é lembrado |
| `WISEDATA_MAIL_WEBHOOK_CACHE_STORE` | cache padrão | com várias instâncias, um cache compartilhado (Redis, banco) |
| `WISEDATA_MAIL_WEBHOOK_ACCEPT_TEST_EVENTS` | `false` | aceita os eventos simulados na homologação |

### O contrato

A fonte é a documentação da API do WiseData Mail; o resumo:

**Cabeçalhos**

| Cabeçalho | |
|---|---|
| `X-WiseData-Signature` | `sha256=` + HMAC-SHA256 hexadecimal de `timestamp + "." + corpo cru`, com o segredo |
| `X-WiseData-Timestamp` | horário Unix, em segundos, que entra no material assinado |
| `X-WiseData-Delivery` | id do lote, **igual em todo reenvio**; `0` no ping de teste |
| `X-WiseData-Event-Count` | quantos eventos vêm no corpo |

**Corpo:** até cem eventos por `POST`.

```json
{
  "events": [
    {
      "id": 8821,
      "type": "delivered",
      "occurred_at": "2026-09-25T14:03:11+00:00",
      "email": "ana@exemplo.com",
      "message_id": 991,
      "campaign_id": null,
      "message_type": "transactional",
      "url": null,
      "reason": null,
      "bounce_classification": null,
      "machine_open": false,
      "is_test": false,
      "space": null
    }
  ],
  "sent_at": "2026-09-25T14:03:21+00:00"
}
```

O ping do botão *Testar* é `{"test": true, "events": [], "sent_at": "..."}`.

**Todo evento traz todos os campos**, com `null` nos que não se aplicam:

| Campo | Quando tem valor |
|---|---|
| `id` | sempre; cresce, serve para deduplicar e perceber buraco |
| `type` | sempre — ver a tabela abaixo |
| `occurred_at` | sempre; ISO 8601 com fuso |
| `email` | sempre |
| `message_id` | sempre; o `id` devolvido no envio |
| `campaign_id` | na campanha; `null` no transacional |
| `message_type` | `marketing` ou `transactional` |
| `url` | só em `click` |
| `reason` | em `bounce`, `suppressed`, `dropped`, `rendering_failure` e `spamreport` |
| `bounce_classification` | em `bounce`, `suppressed`, `dropped` e `rendering_failure` |
| `machine_open` | em `open` e `click`: `true` quando foi máquina (pré-carregamento, antivírus, verificador de links); `false` nos demais |
| `space` | slug do espaço de envio; `null` na conta principal |
| `is_test` | `true` quando o desfecho foi simulado na caixa de homologação |

| `type` | O que aconteceu | Campos próprios |
|---|---|---|
| `delivered` | chegou à caixa do destinatário | — |
| `bounce` | devolveu | `reason` (diagnóstico do servidor), `bounce_classification` |
| `dropped` | o provedor recusou antes de tentar (hoje, vírus) | `reason`, `bounce_classification: virus_detected` |
| `suppressed` | não saiu: o endereço está na supressão | `reason`, `bounce_classification` |
| `rendering_failure` | não foi possível montar a mensagem | `reason`, `bounce_classification: render_failure` |
| `spamreport` | marcado como spam | `reason` |
| `open` | aberto | `machine_open` |
| `click` | clicado | `url`, `machine_open` |
| `unsubscribe` | descadastrou pela página | — |
| `resubscribe` | voltou a se cadastrar pela página | — |

`processed` e `deferred` existem no WiseData Mail mas **não** são entregues por
webhook.

Os valores de `bounce_classification`: `invalid_address`, `previously_bounced`,
`on_account_suppression`, `on_tenant_suppression`, `failed_validation`,
`rejected_by_server` (nenhum adianta repetir); `mailbox_full`,
`delivery_timeout`, `temporary_failure` (adianta mais tarde);
`message_too_large`, `content_rejected`, `attachment_rejected`,
`render_failure` (só mudando a mensagem); `virus_detected`; `undetermined`.

**Entrega**

- Qualquer resposta que não seja `2xx` é falha: o lote volta com espera
  crescente (10s, 30s, 1min, 5min, 15min) por até doze horas, com o mesmo
  `X-WiseData-Delivery` e o mesmo corpo, byte a byte.
- Tempo limite de dez segundos. Redirecionamento não é seguido — um `3xx` é
  falha.
- Vinte lotes falhados seguidos **desativam** o webhook; os eventos do período
  desligado não são reenviados.
- Latência de até cerca de setenta segundos: não é canal de tempo real.
- Trocar o segredo no painel invalida o anterior na hora.

### Fora do Laravel

```php
use WiseData\Mail\Webhook\WebhookPayload;
use WiseData\Mail\Webhook\WebhookSignature;

$corpo = file_get_contents('php://input');

if (! WebhookSignature::verify(
    $corpo,
    $_SERVER['HTTP_X_WISEDATA_TIMESTAMP'] ?? '',
    $_SERVER['HTTP_X_WISEDATA_SIGNATURE'] ?? '',
    $segredo,
)) {
    http_response_code(401);
    exit;
}

foreach (WebhookPayload::parse($corpo)->events as $evento) {
    // $evento->type, $evento->email, $evento->messageId, $evento->subject, ...
}
```

`$evento->subject` é o assunto como saiu para a pessoa (variáveis do modelo
trocadas; na campanha, a variante A/B resolvida). Vem `null` na mensagem de
campanha que não saiu (suprimida, recusada) e em mensagem enviada antes de a API
passar a guardá-lo (06/10/2026).

Três coisas que dão errado e não parecem defeito:

- **Assine o corpo cru.** Decodificar e recodificar o JSON muda a ordem das
  chaves e o escape, e a conferência deixa de bater. No Laravel,
  `$request->getContent()`, nunca `$request->all()`.
- **Compare em tempo constante** — o `verify()` usa `hash_equals`.
- **Deduplique pelo `X-WiseData-Delivery`.** O reenvio leva o mesmo id e o mesmo
  corpo.

## Outra biblioteca HTTP

O padrão é cURL para o pacote não impor escolha a ninguém. Quem já usa Guzzle ou
o cliente do Laravel implementa `WiseData\Mail\Contracts\Transport`.

No Laravel, registre no contêiner — o `Client` e o mailer passam a usá-lo:

```php
// AppServiceProvider::register()
$this->app->singleton(\WiseData\Mail\Contracts\Transport::class, fn () => new MeuTransporte);
```

Fora dele:

```php
new Client(token: '...', transport: new MeuTransporte);
```

## Testes do pacote

```bash
composer install
vendor/bin/phpunit                    # tudo, com Laravel (orchestra/testbench)
vendor/bin/phpunit --testsuite core   # só o que não depende de Laravel
```

Não há chamada de rede na suíte. O CI roda Symfony Mailer 6.4 (sem Laravel),
7.x (Laravel 12) e 8.x (Laravel 13).

## Licença

MIT.
