<?php

declare(strict_types=1);

namespace WiseData\Mail\Exception;

/** O corpo do webhook passou na assinatura, mas não tem o formato do contrato. */
final class InvalidWebhookPayloadException extends WiseDataMailException {}
