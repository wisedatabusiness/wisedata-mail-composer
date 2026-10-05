<?php

declare(strict_types=1);

namespace WiseData\Mail\Laravel\Events;

/** `dropped`: o provedor recusou antes de tentar (hoje, só vírus detectado). */
final class WiseDataMailDropped extends WiseDataMailEvent {}
