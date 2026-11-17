<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Catalog\RouteProfile;
use Proxyma\Domain\Catalog\Target;
use Proxyma\Domain\Health\HealthEpochMap;

#[Api(since: '1.0')]
final readonly class LivePolicyAuthority
{
    /** @var list<Route> */
    public array $eligibleRoutes;

    /** @var array<string, BudgetConstraint> */
    public array $budgets;

    /**
     * @param  list<Route>  $eligibleRoutes
     * @param  array<string, BudgetConstraint>  $budgets
     */
    public function __construct(
        public EffectivePolicy $effectivePolicy,
        public Target $target,
        public RouteProfile $profile,
        array $eligibleRoutes,
        public HealthEpochMap $healthEpochs,
        array $budgets,
    ) {
        if ($eligibleRoutes === []) {
            throw new InvalidArgumentException('LivePolicyAuthority requires at least one eligible route.');
        }

        $this->eligibleRoutes = $eligibleRoutes;

        $normalizedBudgets = [];
        foreach ($budgets as $budget) {
            $normalizedBudgets[$budget->scopeKey] = isset($normalizedBudgets[$budget->scopeKey])
                ? $normalizedBudgets[$budget->scopeKey]->intersect($budget)
                : $budget;
        }
        ksort($normalizedBudgets, SORT_STRING);
        $this->budgets = $normalizedBudgets;
    }
}
