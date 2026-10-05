<?php

declare(strict_types=1);

namespace WiseData\Mail\Laravel\Events;

/** `suppressed`: não saiu porque o endereço está na lista de supressão. */
final class WiseDataMailSuppressed extends WiseDataMailEvent {}
