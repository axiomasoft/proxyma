<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

enum AdmissionLockTier: string
{
    case OwnerPrefix = 'owner_prefix';
    case RouteAndTarget = 'route_and_target';
    case Budgets = 'budgets';
    case FinalCas = 'final_cas';
}
