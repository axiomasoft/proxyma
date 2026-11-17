<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Admission;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Policy\BudgetConstraint;

/**
 * Add-constraints-only budget contributor contract.
 * Mandatory core budgets are always preserved and intersected via BudgetConstraintSet.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface BudgetScopeContributor
{
    /**
     * @return list<BudgetConstraint>
     */
    public function contribute(WireRequest $request, Route $route, CanonicalOrigin $origin): array;
}
