<?php

declare(strict_types=1);

namespace Proxyma\Application\Extensions;

use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Panels\ExtensionKind;
use Proxyma\Domain\Policy\BudgetConstraint;
use Proxyma\Domain\Policy\RouteSubsetEnforcer;
use Proxyma\Domain\Policy\TenantKey;

final readonly class ExtensionRuntime
{
    public function __construct(private DefinitionRegistry $registry) {}

    /** @param list<Route> $routes
     * @param  list<string>  $ids
     * @return list<Route>
     */
    public function filter(array $routes, WireRequest $request, array $ids): array
    {
        foreach ($this->registry->selectExtensions(ExtensionKind::RouteFilter, $ids) as $definition) {
            $routes = RouteSubsetEnforcer::enforceSubset($routes, $definition->implementation->filter($routes, $request));
        }

        return $routes;
    }

    /** @param list<string> $ids
     * @param  list<string>  $coreKeys
     * @return list<BudgetConstraint>
     */
    public function contribute(WireRequest $request, Route $route, TenantKey $tenant, array $ids, array $coreKeys): array
    {
        $extra = [];
        foreach ($this->registry->selectExtensions(ExtensionKind::BudgetScopeContributor, $ids) as $definition) {
            $constraints = $definition->implementation->contribute($request, $route, $request->url->origin);
            if (! array_is_list($constraints)) {
                throw new AdmissionRejected('invalid_budget_contribution');
            }
            foreach ($constraints as $constraint) {
                if (! $constraint instanceof BudgetConstraint
                    || (! in_array($constraint->scopeKey, $coreKeys, true) && ! $definition->budgetSchema->allows($constraint->scopeKey, $tenant))) {
                    throw new AdmissionRejected('undeclared_budget_scope');
                }
                $extra[] = $constraint;
            }
        }

        return $extra;
    }

    /** @param list<object> $budgets
     * @param  list<string>  $ids
     */
    public function assertSharedBudgets(array $budgets, TenantKey $tenant, array $ids): void
    {
        foreach ($this->registry->selectExtensions(ExtensionKind::BudgetScopeContributor, $ids) as $definition) {
            foreach ($budgets as $budget) {
                if ($definition->budgetSchema->allows($budget->scope_key, $tenant) && $budget->tenant_key !== $tenant->value
                    && ! in_array($tenant->value, $definition->budgetSchema->sharedTenants, true)) {
                    throw new AdmissionRejected('budget_sharing_grant_required');
                }
            }
        }
    }
}
