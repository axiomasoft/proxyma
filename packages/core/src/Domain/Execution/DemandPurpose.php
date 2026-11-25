<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum DemandPurpose: string
{
    case Ordinary = 'ordinary';
    case Probe = 'probe';
    case Diagnostic = 'diagnostic';
}
