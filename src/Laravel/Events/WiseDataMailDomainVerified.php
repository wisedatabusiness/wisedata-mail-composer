<?php

declare(strict_types=1);

namespace WiseData\Mail\Laravel\Events;

/** `domain.verified`: o domínio do cliente ficou pronto. O retrato está em `$event->event->domain`. */
final class WiseDataMailDomainVerified extends WiseDataMailEvent {}
