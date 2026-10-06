# Changelog

Formato de [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e
[SemVer](https://semver.org/lang/pt-BR/). A primeira versão listada é sempre
`Client::VERSION` — o `VersionTest` confere, e o CI confere a tag.

## [1.3.0] - 2026-10-06

### Adicionado

- `WebhookEvent::$subject`: o assunto como saiu para a pessoa, que a API passou
  a guardar e a mandar em todo evento. `null` em mensagem anterior à mudança.
  Entra depois de `$metadata`, sem quebrar quem constrói o evento por posição.
- A resposta de `POST /v1/emails` traz `subject` (o assunto final, com as
  variáveis do modelo trocadas); `WiseDataMailFake` devolve o mesmo campo.

## [1.2.0] - 2026-10-06

### Adicionado

- **`tags` e `metadata` do `Envelope` do Laravel** vão nos campos `tags` e
  `metadata` de `POST /v1/emails` (como os bridges oficiais do Symfony fazem
  com Postmark, SendGrid e Mailgun) e voltam nos webhooks:
  `WebhookEvent::$tags` e `WebhookEvent::$metadata`.
- `WiseData\Mail\Validation\SendRules`: as regras de nome de cabeçalho, tags e
  metadados da API, conferidas antes da rede.

### Corrigido

- `TagHeader` e `MetadataHeader` eram encaminhados como cabeçalho
  (`X-Tag`, `X-Metadata-user_id`). A API recusava o `_` com 422 e, se
  passasse, o metadado ficaria visível a quem recebe. Agora nunca viram
  cabeçalho.
- Cabeçalho `X-` com nome que a API recusa (ex.: `X-Pedido_Id`) é recusado
  localmente com `WiseDataMailTransportException` (`validation_failed`), sem
  requisição.

### Alterado

- `WiseDataMailFake` aplica as regras da API (cabeçalho, tags, metadados) e
  devolve o mesmo `422 validation_failed`. Teste que mandava corpo inválido e
  passava agora falha — que é o objetivo.

## [1.1.2] - 2026-10-06

### Corrigido

- `wisedata-mail:test` mandava só texto (`Mail::raw`), e a API exige `html`
  quando não há `template`: o teste voltava 422 mesmo com tudo configurado.
  Agora manda html e texto.

## [1.1.1] - 2026-10-05

### Corrigido

- README e `ApiException`: a API passou a devolver `error: validation_failed`
  no 422 de validação; o texto dizia que ela respondia sem `error`. O pacote
  continua dando o mesmo código quando fala com uma API antiga.

## [1.1.0] - 2026-10-05

### Adicionado

- **Fake oficial para testes no Laravel**: `WiseDataMail::fake()` troca só a
  fronteira HTTP e devolve um `WiseDataMailFake` com `assertSent`,
  `assertSentTo`, `assertNotSentTo`, `assertSentCount`, `assertNothingSent` e
  `failWith()` para simular uma recusa da API.
- **Ponto de injeção do transporte HTTP**: o provider resolve
  `WiseData\Mail\Contracts\Transport` pelo contêiner, tanto para o `Client`
  quanto para o mailer. Um `bind` da aplicação troca o cURL.
- **Comando `php artisan wisedata-mail:test {email} {--mailer=}`**: envia na
  hora pelo mailer e mostra o id aceito, ou o código do erro da API.
- **Receptor de webhook para Laravel**: rota opcional
  (`WISEDATA_MAIL_WEBHOOK_PATH`), middleware `VerifyWebhookSignature` (assinatura
  e janela de cinco minutos), deduplicação por `X-WiseData-Delivery` e um evento
  Laravel por tipo (`WiseDataMailDelivered`, `WiseDataMailBounced`, ...).
- `WebhookSignature`, `WebhookPayload` e `WebhookEvent`, para conferir e ler o
  webhook fora do Laravel.
- Config `webhook.*` com o segredo em `WISEDATA_MAIL_WEBHOOK_SECRET`.
- `WISEDATA_MAIL_USE_ACCOUNT_SENDER` (ou `use_account_sender` no mailer): não
  manda o `from` e deixa a API usar o remetente padrão da conta.
- Suporte declarado e testado a `symfony/mailer` 8.x; CI com Symfony 6.4 (sem
  Laravel), 7.x (Laravel 12) e 8.x (Laravel 13).

### Alterado

- Erro de validação da API (422 com `errors` e sem `error`) chega como
  `ApiException::VALIDACAO` (`validation_failed`), com os campos em
  `$e->extra['errors']`. Antes vinha `unknown_error`, código que nunca foi
  documentado.
- O comportamento do remetente **não** mudou: o `from` continua indo, e o
  `use_account_sender` é opcional. Por isso esta versão é minor.

### Corrigido

- O `User-Agent` dizia `wisedata-mail-php/0.3.0` desde a 1.0.0.
- O README dizia que o remetente era sempre o verificado da conta, mas o mailer
  manda o `from` da mensagem (`MAIL_FROM_ADDRESS`), e a API o usa — recusando
  com 422 quando ele não é um remetente verificado. O README agora descreve o
  comportamento real.
- O README do webhook não listava os eventos, os campos nem a política de
  reenvio.

## [1.0.1] - 2026-09-28

### Alterado

- `homepage` e autoria do `composer.json` apontam para a WiseData Business.

## [1.0.0] - 2026-09-28

Primeira versão estável: contatos, catálogo, envio transacional com anexo e
modelo salvo, e o mailer `wisedatamail` para Laravel.

## [0.3.0] - 2026-09-25

Versão de pré-lançamento.
