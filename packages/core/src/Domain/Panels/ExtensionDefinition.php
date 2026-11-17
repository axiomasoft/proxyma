<?php

declare(strict_types=1);

namespace Proxyma\Domain\Panels;

use Packman\Attributes\Api;
use Proxyma\Contracts\Admission\BudgetScopeContributor;
use Proxyma\Contracts\Admission\LockedAdmissionGuard;
use Proxyma\Contracts\Execution\OutboundObserver;
use Proxyma\Contracts\Execution\OutcomePolicy;
use Proxyma\Contracts\Policy\PolicyDefinitionProvider;
use Proxyma\Contracts\Routing\RouteFilter;
use Proxyma\Contracts\Routing\RouteSelectionStrategy;
use Proxyma\Domain\Execution\AdmissionLockTier;

#[Api(since: '1.0')]
final readonly class ExtensionDefinition
{
    /**
     * @param  array<array-key, string>  $dependencies
     * @param  list<string>  $requiredCapabilities
     */
    public function __construct(
        public string $id,
        public ExtensionKind $kind,
        public RouteFilter|BudgetScopeContributor|LockedAdmissionGuard|RouteSelectionStrategy|OutcomePolicy|OutboundObserver|PolicyDefinitionProvider $implementation,
        public int $version = 1,
        public array $dependencies = [],
        public int $priority = 0,
        public ?AdmissionLockTier $tier = null,
        public array $requiredCapabilities = [],
        public ?BudgetScopeSchema $budgetSchema = null,
    ) {
        PanelSlot::identifier($id);
        $valid = match ($kind) {
            ExtensionKind::RouteFilter => $implementation instanceof RouteFilter,
            ExtensionKind::BudgetScopeContributor => $implementation instanceof BudgetScopeContributor,
            ExtensionKind::LockedAdmissionGuard => $implementation instanceof LockedAdmissionGuard,
            ExtensionKind::Strategy => $implementation instanceof RouteSelectionStrategy,
            ExtensionKind::OutcomePolicy => $implementation instanceof OutcomePolicy,
            ExtensionKind::Observer => $implementation instanceof OutboundObserver,
            ExtensionKind::PolicyDefinitions => $implementation instanceof PolicyDefinitionProvider,
        };
        if (! $valid || $version < 1 || (($kind === ExtensionKind::LockedAdmissionGuard) !== ($tier !== null))
            || ! array_is_list($dependencies) || count(array_unique($dependencies)) !== count($dependencies)) {
            throw new DefinitionError('Invalid typed extension descriptor.');
        }
        foreach ($dependencies as $dependency) {
            PanelSlot::identifier($dependency);
        }
    }
}
