<?php

declare(strict_types=1);

namespace WiseData\Mail\Exception;

/**
 * A requisição não chegou: DNS, TLS, tempo esgotado, conexão recusada.
 *
 * Separada da `ApiException` porque a reação é outra. Um `unknown_field` não
 * melhora tentando de novo; isto aqui, sim — e o pacote já tentou (ver
 * `Client::$retries`) antes de chegar até aqui.
 */
class TransportException extends WiseDataMailException {}
