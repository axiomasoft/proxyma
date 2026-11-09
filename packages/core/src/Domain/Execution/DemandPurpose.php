<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

enum DemandPurpose: string
{
    case Ordinary = 'ordinary';
    case Probe = 'probe';
    case Diagnostic = 'diagnostic';
}
