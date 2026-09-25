<?php

declare(strict_types=1);

namespace WiseData\Mail\Exception;

use RuntimeException;

/**
 * A raiz de tudo que este pacote lança.
 *
 * Existe para o `catch` de quem integra poder ser um só quando ele não se
 * importa com o motivo — que é o caso de quem só quer registrar a falha e
 * seguir.
 */
class WiseDataMailException extends RuntimeException {}
