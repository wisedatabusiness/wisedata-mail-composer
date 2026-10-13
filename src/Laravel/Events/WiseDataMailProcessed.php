<?php

declare(strict_types=1);

namespace WiseData\Mail\Laravel\Events;

/** `processed`: o provedor aceitou a mensagem. Só chega se o endpoint o assinou — é um por envio. */
final class WiseDataMailProcessed extends WiseDataMailEvent {}
