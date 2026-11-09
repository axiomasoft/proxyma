<?php

declare(strict_types=1);

namespace Proxyma\Domain\Panels;

enum ExtensionKind: string
{
    case RouteFilter = 'route_filter';
    case BudgetScopeContributor = 'budget_scope_contributor';
    case LockedAdmissionGuard = 'locked_admission_guard';
}
