<?php

declare(strict_types=1);

namespace Proxyma\Domain\Health;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum TargetAvailability: string
{
    case Unknown = 'unknown';
    case Available = 'available';
    case Suspect = 'suspect';
    case Unavailable = 'unavailable';
}
