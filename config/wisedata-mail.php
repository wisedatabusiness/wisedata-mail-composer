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
    ],

];
