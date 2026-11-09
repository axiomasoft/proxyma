<?php

declare(strict_types=1);

namespace Proxyma\Domain\Health;

enum TargetAvailability: string
{
    case Unknown = 'unknown';
    case Available = 'available';
    case Suspect = 'suspect';
    case Unavailable = 'unavailable';
}
