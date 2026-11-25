<?php

declare(strict_types=1);

use Proxyma\Contracts\Admission\Admission;
use Proxyma\Contracts\Admission\BudgetScopeContributor;
use Proxyma\Contracts\Admission\ClaimActivationAuthority;
use Proxyma\Contracts\Admission\LockedAdmissionGuard;
use Proxyma\Contracts\Admission\OwnerAuthority;
use Proxyma\Contracts\Admission\SqlConnectionParticipant;
use Proxyma\Contracts\Admission\TransactionCoordinator;
use Proxyma\Contracts\Admission\WorkBudget;
use Proxyma\Contracts\Authorization\Authorizer;
use Proxyma\Contracts\Authorization\TenantContextResolver;
use Proxyma\Contracts\Bindings\BindingCommands;
use Proxyma\Contracts\Bindings\BindingQueries;
use Proxyma\Contracts\Bindings\SubjectResolver;
use Proxyma\Contracts\Catalog\CandidateSnapshots;
use Proxyma\Contracts\Catalog\CatalogCommands;
use Proxyma\Contracts\Catalog\CatalogMutationFence;
use Proxyma\Contracts\Catalog\CatalogQueries;
use Proxyma\Contracts\Catalog\RouteAccessChecks;
use Proxyma\Contracts\Catalog\SharedRouteCommands;
use Proxyma\Contracts\Execution\Completion;
use Proxyma\Contracts\Execution\DestinationResolver;
use Proxyma\Contracts\Execution\FiniteExecutionPort;
use Proxyma\Contracts\Execution\LockedCompletionReducer;
use Proxyma\Contracts\Execution\OutboundClient;
use Proxyma\Contracts\Execution\OutboundObserver;
use Proxyma\Contracts\Execution\OutcomePolicy;
use Proxyma\Contracts\Execution\PhysicalTransport;
use Proxyma\Contracts\Extensions\ExtensionProvider;
use Proxyma\Contracts\Health\DiagnosticsQueries;
use Proxyma\Contracts\Policy\PolicyCommands;
use Proxyma\Contracts\Policy\PolicyDefinitionProvider;
use Proxyma\Contracts\Policy\PolicyResolver;
use Proxyma\Contracts\Policy\SettingDefinitionProvider;
use Proxyma\Contracts\Routing\RandomIndex;
use Proxyma\Contracts\Routing\RouteFilter;
use Proxyma\Contracts\Routing\RouteSelectionStrategy;
use Proxyma\Contracts\Security\SecretResolver;
use Proxyma\Contracts\Security\TlsTrustPolicy;

(static function (): void {
    $contracts = 'Proxyma\\Contracts\\';

    /** @var array<string, class-string> $aliases */
    $aliases = [
        $contracts.'Admission' => Admission::class,
        $contracts.'Authorizer' => Authorizer::class,
        $contracts.'BindingCommands' => BindingCommands::class,
        $contracts.'BindingQueries' => BindingQueries::class,
        $contracts.'BudgetScopeContributor' => BudgetScopeContributor::class,
        $contracts.'CandidateSnapshots' => CandidateSnapshots::class,
        $contracts.'CatalogCommands' => CatalogCommands::class,
        $contracts.'CatalogMutationFence' => CatalogMutationFence::class,
        $contracts.'CatalogQueries' => CatalogQueries::class,
        $contracts.'ClaimActivationAuthority' => ClaimActivationAuthority::class,
        $contracts.'Completion' => Completion::class,
        $contracts.'DestinationResolver' => DestinationResolver::class,
        $contracts.'DiagnosticsQueries' => DiagnosticsQueries::class,
        $contracts.'ExtensionProvider' => ExtensionProvider::class,
        $contracts.'FiniteExecutionPort' => FiniteExecutionPort::class,
        $contracts.'LockedAdmissionGuard' => LockedAdmissionGuard::class,
        $contracts.'LockedCompletionReducer' => LockedCompletionReducer::class,
        $contracts.'OutboundClient' => OutboundClient::class,
        $contracts.'OutboundObserver' => OutboundObserver::class,
        $contracts.'OutcomePolicy' => OutcomePolicy::class,
        $contracts.'OwnerAuthority' => OwnerAuthority::class,
        $contracts.'PhysicalTransport' => PhysicalTransport::class,
        $contracts.'PolicyCommands' => PolicyCommands::class,
        $contracts.'PolicyDefinitionProvider' => PolicyDefinitionProvider::class,
        $contracts.'PolicyResolver' => PolicyResolver::class,
        $contracts.'RandomIndex' => RandomIndex::class,
        $contracts.'RouteAccessChecks' => RouteAccessChecks::class,
        $contracts.'RouteFilter' => RouteFilter::class,
        $contracts.'RouteSelectionStrategy' => RouteSelectionStrategy::class,
        $contracts.'SecretResolver' => SecretResolver::class,
        $contracts.'SettingDefinitionProvider' => SettingDefinitionProvider::class,
        $contracts.'SharedRouteCommands' => SharedRouteCommands::class,
        $contracts.'SqlConnectionParticipant' => SqlConnectionParticipant::class,
        $contracts.'SubjectResolver' => SubjectResolver::class,
        $contracts.'TenantContextResolver' => TenantContextResolver::class,
        $contracts.'TlsTrustPolicy' => TlsTrustPolicy::class,
        $contracts.'TransactionCoordinator' => TransactionCoordinator::class,
        $contracts.'WorkBudget' => WorkBudget::class,
    ];

    foreach ($aliases as $legacy => $target) {
        if (! interface_exists($legacy, false) && ! class_exists($legacy, false)) {
            class_alias($target, $legacy);
        }
    }
})();
