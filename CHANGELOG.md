# Changelog

Formato de [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e
[SemVer](https://semver.org/lang/pt-BR/). A primeira versão listada é sempre
`Client::VERSION` — o `VersionTest` confere, e o CI confere a tag.

## [1.6.0] - 2026-10-16

### Adicionado

- **Seus clientes, cada um no seu espaço** — para o produto que envia em nome
  dos clientes dele, com a **chave de plataforma** (emitida pelo Dono em
  Configurações › Integrações; alcança todo espaço da conta, inclusive os
  criados depois):
  - `spaces()->upsert($externalId, $name, $sendLimit)`, `find()`, `list()`,
    `delete()`. A identidade é o id do cliente no seu sistema; repetir a
    chamada atualiza em vez de duplicar. Sai sempre pela conta, mesmo de um
    cliente com `forSpace()`.
  - `domains()->register()`, `find()`, `list()`, `verify()`, `delete()` — no
    espaço do `forSpace()`. `register()` devolve os registros DNS a publicar.
  - `senders()->upsert($fromEmail, $fromName, $label, $replyTo)`, `find()`,
    `list()`, `delete()`. O domínio exato do endereço precisa estar cadastrado
    no espaço; o remetente da API nunca vira o padrão.
- **Eventos de domínio no webhook**: `domain.verified`, `domain.failed` e
  `domain.degraded`, com as constantes em `WebhookEvent`, o retrato tipado em
  `$event->domain` (`DomainStatus`: `domain`, `readyToSend`, `verifiedAt`,
  `failedRecords`), `isAboutDomain()` e os eventos Laravel
  `WiseDataMailDomainVerified`, `WiseDataMailDomainFailed` e
  `WiseDataMailDomainDegraded`. Opcionais: só chegam ao endpoint que os marcou.
  Chegam sem `email` (`''`) e com `space` preenchido.
- `ApiException::retryAfter()`: os segundos de espera de `rate_limited` (o
  `verify()` aceita um por minuto por domínio) e de `provider_unavailable`.
- A fake responde ao `PUT` de espaço, domínio e remetente no formato da API, e
  ganha `assertSpaceUpserted()`, `assertDomainRegistered($domain, space:)` e
  `assertSenderUpserted($fromEmail, space:)`. O `slug` da fake é o próprio
  `external_id`.

### Alterado

- O domínio só fica pronto com **DMARC** publicado (no próprio domínio ou no
  organizacional, quando o envio é de um subdomínio). Vale para a API e para o
  painel.

## [1.5.2] - 2026-10-12

### Alterado

- **`wisedata-mail:test` manda `PING` e o corpo é `PONG`**, no lugar da frase
  em português: entendido em qualquer idioma, sem o pacote precisar saber a
  língua da aplicação.
- O comando **não imprime mais o id da mensagem**. Não ajudava quem roda — a
  prova é o e-mail chegar — e o terminal do servidor vai parar em log de deploy
  e em print de chamado.

## [1.5.1] - 2026-10-12

### Alterado

- `sendBatch()` confere o lote com as regras da API **antes da rede** e lança
  `ApiException` (`validation_failed`, com `errors` no caminho do item, como
  `emails.1.attachments`) sem subir nada. Num lote de cem, um cabeçalho com
  nome inválido recusaria todas as mensagens só depois de enviar o corpo inteiro.

## [1.5.0] - 2026-10-12

### Adicionado

- **Envio em lote**: `emails()->sendBatch([...])`, até 100 mensagens por
  chamada (`POST /v1/emails/batch`). Cada item aceita os campos de `send()`,
  menos anexo, e mais `idempotency_key`. A resposta traz o desfecho de cada
  mensagem, na ordem, com `index`: aceita (`id`, `status`, `replayed`) ou
  recusada (`status` HTTP e o corpo de `error`). Só a estrutura inválida e a
  conta sem condição de enviar recusam o lote inteiro. A fake reconhece a rota.
- **Cancelamento em um clique no marketing**: `List-Unsubscribe` e
  `List-Unsubscribe-Post` passam a ser aceitos em `headers` quando a mensagem é
  `marketing`, conferidos localmente (entradas `<https://...>`/`<mailto:...>`;
  o Post só com `List-Unsubscribe=One-Click` e um `https` na lista). O transport
  os encaminha do `Mailable`, menos no transacional declarado.
- **`processed` no webhook**: `WebhookEvent::PROCESSED` e o evento Laravel
  `WiseDataMailProcessed`. Só chega ao endpoint que o assinou no painel.
- `sender_check_failed` na lista de `bounce_classification` do README.

## [1.4.1] - 2026-10-11

### Alterado

- **Documentação do alcance da chave**, acompanhando a API: a chave sem espaço
  marcado age **só na conta principal** e deixou de alcançar os espaços pelo
  `X-WiseData-Space`. Com ela, `forSpace()` ou `WISEDATA_MAIL_SPACE` recebem
  403 `space_not_allowed`. Nenhuma mudança de código no pacote — quem usa uma
  chave por espaço não precisa fazer nada.
- O painel passou a ter o bloco **Integração** também para a conta principal,
  com a linha `WISEDATA_MAIL_CONTACT_LISTS` das listas dela.

## [1.4.0] - 2026-10-06

### Adicionado

- **Listas padrão pelo `.env`**: `WISEDATA_MAIL_CONTACT_LISTS=clientes-finances,leads`.
  Todo `contacts()->upsert()` que não fala de listas manda `add_lists` com
  elas — acrescenta, nunca substitui. Fora do Laravel,
  `Client::withContactLists()`. `forSpace()` para outro espaço as larga.
- A lista pela **chave** (`clientes-finances`) em `upsert` (`lists` e
  `add_lists`), `addToLists()` e `removeFromLists()`. O id continua aceito.
- `add_lists` no `upsert`: entrar em listas sem sair das outras, numa chamada só.

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
