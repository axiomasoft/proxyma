<?php

declare(strict_types=1);

namespace Proxyma\Plugins;

use Override;
use Packman\Attributes\Api;
use Packman\Extensions\Concerns\Composition\ContributesToSlots;
use Packman\Extensions\Concerns\Composition\ProvidesCapabilities;
use Packman\Extensions\Concerns\Composition\ProvidesPanels;
use Packman\Extensions\Concerns\Configuration\Configurable;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Concerns\Runtime\ProvidesDiagnostics;
use Packman\Extensions\Contracts\Composition\WithCapabilities;
use Packman\Extensions\Contracts\Composition\WithPanels;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Extensions\Contracts\Runtime\WithDiagnostics;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Enums\Contributions\Cardinality;
use Packman\Plugins\Enums\Contributions\SlotKind;
use Packman\Plugins\Laravel\PluginRegistrar;
use Proxyma\Application\Admission\TransactionCoordinator;
use Proxyma\Application\Bindings\DatabaseBindingCommands;
use Proxyma\Application\Bindings\DatabaseBindingQueries;
use Proxyma\Application\Catalog\DatabaseCatalogCommands;
use Proxyma\Application\Execution\DestinationPolicy;
use Proxyma\Application\Execution\OutboundExecutor;
use Proxyma\Application\Policy\PolicyResolver;
use Proxyma\Application\Policy\UpdatePolicy;
use Proxyma\Application\Recovery\CompletionCoordinator;
use Proxyma\Application\Routing\SecureRandomIndex;
use Proxyma\Configuration\ProxymaOptions;
use Proxyma\Contracts\Admission\BudgetScopeContributor;
use Proxyma\Contracts\Admission\LockedAdmissionGuard;
use Proxyma\Contracts\Admission\OwnerAuthority;
use Proxyma\Contracts\Admission\TransactionCoordinator as TransactionCoordinatorContract;
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
use Proxyma\Contracts\Execution\LockedCompletionReducer;
use Proxyma\Contracts\Execution\OutboundClient;
use Proxyma\Contracts\Execution\OutboundObserver;
use Proxyma\Contracts\Execution\OutcomePolicy;
use Proxyma\Contracts\Execution\PhysicalTransport;
use Proxyma\Contracts\Extensions\ExtensionProvider;
use Proxyma\Contracts\Health\DiagnosticsQueries;
use Proxyma\Contracts\Policy\PolicyCommands;
use Proxyma\Contracts\Policy\PolicyDefinitionProvider;
use Proxyma\Contracts\Policy\PolicyResolver as PolicyResolverContract;
use Proxyma\Contracts\Routing\RandomIndex;
use Proxyma\Contracts\Routing\RouteFilter;
use Proxyma\Contracts\Routing\RouteSelectionStrategy;
use Proxyma\Contracts\Security\SecretResolver;
use Proxyma\Contracts\Security\TlsTrustPolicy;
use Proxyma\Database\ProxymaSchemaSets;
use Proxyma\Diagnostics\ProxymaDoctorCheck;
use Proxyma\Errors\ProxymaProblemDefinitions;
use Proxyma\Infrastructure\Admission\DatabaseOwnerAuthority;
use Proxyma\Infrastructure\Database\CandidateSnapshotQuery;
use Proxyma\Infrastructure\Database\CompletionObservationReducer;
use Proxyma\Infrastructure\Database\DatabaseCatalogQueries;
use Proxyma\Infrastructure\Database\DatabaseDiagnosticsQueries;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Http\PhysicalHttpTransport;
use Proxyma\Infrastructure\Http\TlsTrust;
use Proxyma\Infrastructure\Secrets\EncryptedSecretStore;
use Proxyma\Panels\ProxymaPanelProvider;

/**
 * Stateless Packman extension plugin composing Proxyma's configuration, schema, capabilities, slots, panels, and diagnostics.
 */
#[Api(since: '1.0')]
final class ProxymaCorePlugin implements ExtensionPlugin, WithCapabilities, WithConfiguration, WithDiagnostics, WithPanels, WithSchema, WithSlots
{
    use Configurable;
    use ContributesToSlots;
    use OwnsSchema;
    use ProvidesCapabilities;
    use ProvidesDiagnostics;
    use ProvidesPanels;

    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'axiomasoft.proxyma',
            version: '1.0.0',
            resourceRoot: dirname(__DIR__, 2),
            databases: ['pgsql'],
        );
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void {}

    #[Override]
    public function configRoot(): string
    {
        return 'proxyma';
    }

    #[Override]
    public function defaultConfigPath(): string
    {
        return dirname(__DIR__, 2).'/config/proxyma.php';
    }

    /** @return class-string<ProxymaOptions> */
    #[Override]
    public function optionsClass(): string
    {
        return ProxymaOptions::class;
    }

    #[Override]
    public function tablePrefix(): string
    {
        return PackageSchema::prefix();
    }

    /** @return list<string> */
    #[Override]
    public function tableNouns(): array
    {
        return array_keys(PackageSchema::tables());
    }

    #[Override]
    public function migrationPath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations';
    }

    /** @return list<class-string> */
    #[Override]
    public function schemaSetProviders(): array
    {
        return [ProxymaSchemaSets::class];
    }

    /** @return array<class-string, string> */
    #[Override]
    public function definedCapabilities(): array
    {
        return [
            Authorizer::class => Cardinality::OptionalSingle->value,
            TenantContextResolver::class => Cardinality::OptionalSingle->value,
            CatalogMutationFence::class => Cardinality::OptionalSingle->value,
            CatalogCommands::class => Cardinality::RequiredSingle->value,
            SharedRouteCommands::class => Cardinality::RequiredSingle->value,
            CatalogQueries::class => Cardinality::RequiredSingle->value,
            RouteAccessChecks::class => Cardinality::RequiredSingle->value,
            CandidateSnapshots::class => Cardinality::RequiredSingle->value,
            BindingCommands::class => Cardinality::RequiredSingle->value,
            BindingQueries::class => Cardinality::RequiredSingle->value,
            PolicyCommands::class => Cardinality::RequiredSingle->value,
            PolicyResolverContract::class => Cardinality::RequiredSingle->value,
            DiagnosticsQueries::class => Cardinality::RequiredSingle->value,
            RandomIndex::class => Cardinality::RequiredSingle->value,
            SecretResolver::class => Cardinality::RequiredSingle->value,
            TlsTrustPolicy::class => Cardinality::RequiredSingle->value,
            PhysicalTransport::class => Cardinality::RequiredSingle->value,
            TransactionCoordinatorContract::class => Cardinality::RequiredSingle->value,
            OwnerAuthority::class => Cardinality::RequiredSingle->value,
            Completion::class => Cardinality::RequiredSingle->value,
            LockedCompletionReducer::class => Cardinality::RequiredSingle->value,
            DestinationResolver::class => Cardinality::RequiredSingle->value,
            OutboundClient::class => Cardinality::RequiredSingle->value,
        ];
    }

    /** @return array<class-string, class-string> */
    #[Override]
    public function providedCapabilities(): array
    {
        return [
            CatalogCommands::class => DatabaseCatalogCommands::class,
            SharedRouteCommands::class => DatabaseCatalogCommands::class,
            CatalogQueries::class => DatabaseCatalogQueries::class,
            RouteAccessChecks::class => DatabaseCatalogQueries::class,
            CandidateSnapshots::class => CandidateSnapshotQuery::class,
            BindingCommands::class => DatabaseBindingCommands::class,
            BindingQueries::class => DatabaseBindingQueries::class,
            PolicyCommands::class => UpdatePolicy::class,
            PolicyResolverContract::class => PolicyResolver::class,
            DiagnosticsQueries::class => DatabaseDiagnosticsQueries::class,
            RandomIndex::class => SecureRandomIndex::class,
            SecretResolver::class => EncryptedSecretStore::class,
            TlsTrustPolicy::class => TlsTrust::class,
            PhysicalTransport::class => PhysicalHttpTransport::class,
            TransactionCoordinatorContract::class => TransactionCoordinator::class,
            OwnerAuthority::class => DatabaseOwnerAuthority::class,
            Completion::class => CompletionCoordinator::class,
            LockedCompletionReducer::class => CompletionObservationReducer::class,
            DestinationResolver::class => DestinationPolicy::class,
            OutboundClient::class => OutboundExecutor::class,
        ];
    }

    /** @return array<class-string, array{kind: string, cardinality: string}> */
    #[Override]
    public function definedSlots(): array
    {
        return [
            RouteFilter::class => [
                'kind' => SlotKind::Restriction->value,
                'cardinality' => Cardinality::Many->value,
            ],
            BudgetScopeContributor::class => [
                'kind' => SlotKind::Contributor->value,
                'cardinality' => Cardinality::Many->value,
            ],
            LockedAdmissionGuard::class => [
                'kind' => SlotKind::Guard->value,
                'cardinality' => Cardinality::Many->value,
            ],
            RouteSelectionStrategy::class => [
                'kind' => SlotKind::Handler->value,
                'cardinality' => Cardinality::Many->value,
            ],
            OutcomePolicy::class => [
                'kind' => SlotKind::Handler->value,
                'cardinality' => Cardinality::Many->value,
            ],
            OutboundObserver::class => [
                'kind' => SlotKind::EventSink->value,
                'cardinality' => Cardinality::Many->value,
            ],
            PolicyDefinitionProvider::class => [
                'kind' => SlotKind::Handler->value,
                'cardinality' => Cardinality::Many->value,
            ],
            SubjectResolver::class => [
                'kind' => SlotKind::Handler->value,
                'cardinality' => Cardinality::Many->value,
            ],
            ExtensionProvider::class => [
                'kind' => SlotKind::Contributor->value,
                'cardinality' => Cardinality::Many->value,
            ],
        ];
    }

    /** @return list<class-string> */
    #[Override]
    public function panelProviders(): array
    {
        return [ProxymaPanelProvider::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function healthChecks(): array
    {
        return [ProxymaDoctorCheck::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function problemProviders(): array
    {
        return [ProxymaProblemDefinitions::class];
    }
}
