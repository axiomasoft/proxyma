<?php

declare(strict_types=1);

namespace Proxyma\Application\Extensions;

use Packman\Attributes\Api;
use Proxyma\Contracts\Admission\BudgetScopeContributor;
use Proxyma\Contracts\Routing\RouteFilter;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Panels\ExtensionKind;
use Proxyma\Domain\Policy\BudgetConstraint;
use Proxyma\Domain\Policy\RouteSubsetEnforcer;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Internal\Database\RowValues;

#[Api(since: '1.0')]
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
            if (! $definition->implementation instanceof RouteFilter) {
                throw new AdmissionRejected('invalid_route_filter');
            }
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
            if (! $definition->implementation instanceof BudgetScopeContributor || $definition->budgetSchema === null) {
                throw new AdmissionRejected('undeclared_budget_scope');
            }
            $constraints = $definition->implementation->contribute($request, $route, $request->url->origin);
            foreach ($constraints as $constraint) {
                if (! in_array($constraint->scopeKey, $coreKeys, true) && ! $definition->budgetSchema->allows($constraint->scopeKey, $tenant)) {
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
            if ($definition->budgetSchema === null) {
                throw new AdmissionRejected('undeclared_budget_scope');
            }
            foreach ($budgets as $budget) {
                if ($definition->budgetSchema->allows(RowValues::colStr($budget, 'scope_key'), $tenant)
                    && RowValues::colStr($budget, 'tenant_key') !== $tenant->value
                    && ! in_array($tenant->value, $definition->budgetSchema->sharedTenants, true)) {
                    throw new AdmissionRejected('budget_sharing_grant_required');
                }
            }
        }
    }
}
