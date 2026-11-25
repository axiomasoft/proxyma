<?php

declare(strict_types=1);

namespace Proxyma\Domain\Panels;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum ExtensionKind: string
{
    case RouteFilter = 'route_filter';
    case BudgetScopeContributor = 'budget_scope_contributor';
    case LockedAdmissionGuard = 'locked_admission_guard';
    case Strategy = 'strategy';
    case OutcomePolicy = 'outcome_policy';
    case Observer = 'observer';
    case PolicyDefinitions = 'policy_definitions';
}
