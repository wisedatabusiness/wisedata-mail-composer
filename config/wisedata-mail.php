<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Token da API
    |--------------------------------------------------------------------------
    |
    | O `wdm_...` emitido em Configurações › Integrações, no WiseData Mail.
    |
    | Ele vale tanto quanto a senha da conta: quem o tem lê e escreve a base de
    | contatos inteira. Vai no `.env`, nunca no repositório.
    |
    */

    'token' => env('WISEDATA_MAIL_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Endereço da API
    |--------------------------------------------------------------------------
    |
    | Só mude em desenvolvimento, para apontar para a instância local.
    |
    | Precisa ser `https`, e o cliente recusa a construção quando não é: o token
    | vai em toda requisição e sem TLS viaja legível. A exceção é o endereço
    | local (`localhost`, `127.0.0.1`, `.test`), onde não há caminho para
    | escutar.
    |
    */

    'base_url' => env('WISEDATA_MAIL_URL', 'https://api.wisedatamail.com'),

    /*
    |--------------------------------------------------------------------------
    | Espaço de envio
    |--------------------------------------------------------------------------
    |
    | O slug do espaço, quando a chave alcança mais de um. Deixe vazio se ela
    | alcança um só ou a conta inteira — com vários vinculados e sem isto aqui,
    | a API recusa em vez de escolher por você.
    |
    */

    'space' => env('WISEDATA_MAIL_SPACE'),

    /*
    |--------------------------------------------------------------------------
    | Tentativas extra
    |--------------------------------------------------------------------------
    |
    | Quantas vezes repetir em 429, 5xx e falha de rede, com espera dobrando.
    | Zero desliga — o que faz sentido quando a chamada já acontece dentro de um
    | job com repetição própria, para as duas não se multiplicarem.
    |
    */

    'retries' => env('WISEDATA_MAIL_RETRIES', 2),

    /*
    |--------------------------------------------------------------------------
    | O mailer (MAIL_MAILER=wisedatamail)
    |--------------------------------------------------------------------------
    |
    | Só vale para o envio de e-mail pelo `Mail::` do Laravel. Sincronização de
    | contato não passa por aqui.
    |
    | `message_type` vazio deixa a CHAVE decidir — é o padrão e o caminho de
    | menor atrito: nenhum Mailable precisa mudar. Preencha só se esta aplicação
    | manda um tipo diferente do que a chave assume.
    |
    | `retries` é ZERO de propósito, e não é descuido. Repetir um POST de envio
    | é arriscar mandar o e-mail duas vezes: o servidor pode ter aceitado e caído
    | ao responder. Aqui quem repete é a fila do Laravel, com o backoff dela —
    | aumentar este número põe duas camadas tentando a mesma coisa.
    |
    */

    'mail' => [
        'message_type' => env('WISEDATA_MAIL_MESSAGE_TYPE'),
        'retries' => env('WISEDATA_MAIL_MAIL_RETRIES', 0),

        /*
         * O remetente. Desligado (padrão), o `from` da mensagem — o
         * `MAIL_FROM_ADDRESS`, ou o `from()` do Mailable — vai na chamada e
         * precisa ser um remetente VERIFICADO no WiseData Mail; se não for, a
         * API recusa com 422 (`validation_failed`). Ligado, o `from` não vai e
         * a mensagem sai pelo remetente padrão da conta.
         */
        'use_account_sender' => env('WISEDATA_MAIL_USE_ACCOUNT_SENDER', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook (eventos de entrega, devolução, abertura...)
    |--------------------------------------------------------------------------
    |
    | `secret` é o segredo exibido UMA vez ao criar o webhook em Configurações ›
    | Webhooks. Sem ele, o receptor recusa tudo.
    |
    | `path` liga a rota pronta (`POST /{path}`). Vazio, nenhuma rota é
    | registrada: o pacote não abre endpoint em aplicação que não pediu.
    |
    | `tolerance` é a janela, em segundos, entre o `X-WiseData-Timestamp` e o
    | relógio daqui. É o que impede reenviar um par (corpo, assinatura)
    | capturado.
    |
    | `dedupe_ttl` é por quanto tempo o `X-WiseData-Delivery` já processado é
    | lembrado. A API reenvia um lote por até doze horas; um dia cobre com folga.
    | `cache_store` vazio usa o cache padrão — num servidor com várias
    | instâncias, precisa ser um cache compartilhado (Redis, banco).
    |
    | `accept_test_events`: o desfecho simulado na caixa de homologação chega ao
    | MESMO endereço dos reais. Desligado, ele é descartado antes de virar
    | evento — senão uma devolução simulada descadastraria um cliente de verdade.
    |
    */

    'webhook' => [
        'secret' => env('WISEDATA_MAIL_WEBHOOK_SECRET'),
        'path' => env('WISEDATA_MAIL_WEBHOOK_PATH'),
        'tolerance' => (int) env('WISEDATA_MAIL_WEBHOOK_TOLERANCE', 300),
        'dedupe_ttl' => (int) env('WISEDATA_MAIL_WEBHOOK_DEDUPE_TTL', 86400),
        'cache_store' => env('WISEDATA_MAIL_WEBHOOK_CACHE_STORE'),
        'accept_test_events' => env('WISEDATA_MAIL_WEBHOOK_ACCEPT_TEST_EVENTS', false),
    ],

];
