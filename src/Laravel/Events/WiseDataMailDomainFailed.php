<?php

declare(strict_types=1);

namespace WiseData\Mail\Laravel\Events;

/** `domain.failed`: a conferência automática desistiu sem o domínio ficar pronto; `domains()->verify()` a retoma. */
final class WiseDataMailDomainFailed extends WiseDataMailEvent {}
