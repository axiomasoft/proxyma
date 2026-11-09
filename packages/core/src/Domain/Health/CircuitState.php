<?php

declare(strict_types=1);

namespace Proxyma\Domain\Health;

enum CircuitState: string
{
    case Unknown = 'unknown';
    case Closed = 'closed';
    case Open = 'open';
    case HalfOpen = 'half_open';
}
