<?php

declare(strict_types=1);

namespace Proxyma\Domain\Health;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum CircuitState: string
{
    case Unknown = 'unknown';
    case Closed = 'closed';
    case Open = 'open';
    case HalfOpen = 'half_open';
}
