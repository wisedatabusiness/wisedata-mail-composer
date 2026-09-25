<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Carregamento das classes nos testes
|--------------------------------------------------------------------------
|
| Usa o autoloader do Composer quando ele existe. Quando não existe — antes do
| primeiro `composer install`, ou rodando a suíte com o PHPUnit de outro
| projeto —, registra um PSR-4 mínimo para `src/` e `tests/`.
|
| O pacote não tem nenhuma dependência de produção, então este caminho não é
| gambiarra: é o que permite conferir o pacote sem baixar nada.
|
*/

$autoload = __DIR__.'/../vendor/autoload.php';

if (file_exists($autoload)) {
    require $autoload;

    return;
}

spl_autoload_register(static function (string $classe): void {
    $mapa = [
        'WiseData\\Mail\\Tests\\' => __DIR__.'/',
        'WiseData\\Mail\\' => __DIR__.'/../src/',
    ];

    foreach ($mapa as $prefixo => $pasta) {
        if (! str_starts_with($classe, $prefixo)) {
            continue;
        }

        $caminho = $pasta.str_replace('\\', '/', substr($classe, strlen($prefixo))).'.php';

        if (file_exists($caminho)) {
            require $caminho;
        }

        return;
    }
});
