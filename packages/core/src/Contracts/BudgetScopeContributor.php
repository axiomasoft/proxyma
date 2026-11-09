<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Policy\BudgetConstraint;

/**
 * Add-constraints-only budget contributor contract.
 * Mandatory core budgets are always preserved and intersected via BudgetConstraintSet.
 */
interface BudgetScopeContributor
{
    /**
     * @return list<BudgetConstraint>
     */
    public function contribute(WireRequest $request, Route $route, CanonicalOrigin $origin): array;
}
