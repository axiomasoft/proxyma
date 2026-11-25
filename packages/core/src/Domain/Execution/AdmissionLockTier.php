<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum AdmissionLockTier: string
{
    case OwnerPrefix = 'owner_prefix';
    case RouteAndTarget = 'route_and_target';
    case AdapterGuard = 'adapter_guard';
    case Budgets = 'budgets';
    case FinalCas = 'final_cas';
}
