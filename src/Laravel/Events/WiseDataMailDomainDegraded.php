<?php

declare(strict_types=1);

namespace WiseData\Mail\Laravel\Events;

/** `domain.degraded`: estava pronto e deixou de estar; `failed_records` diz qual registro sumiu. */
final class WiseDataMailDomainDegraded extends WiseDataMailEvent {}
