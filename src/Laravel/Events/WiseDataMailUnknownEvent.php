<?php

declare(strict_types=1);

namespace WiseData\Mail\Laravel\Events;

/**
 * Um `type` que esta versão do pacote não conhece.
 *
 * Existe para um tipo novo da API não sumir em silêncio entre a publicação dele
 * e a atualização do pacote.
 */
final class WiseDataMailUnknownEvent extends WiseDataMailEvent {}
