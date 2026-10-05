<?php

declare(strict_types=1);

namespace WiseData\Mail\Laravel\Events;

/** `bounce`: devolveu. `reason` e `bounceClassification` dizem por quê. */
final class WiseDataMailBounced extends WiseDataMailEvent {}
