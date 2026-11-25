<?php

declare(strict_types=1);

namespace Proxyma\Laravel\Registrars;

use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Concerns\RegistersNothingOnBoot;
use Packman\Foundation\Contracts\PackageRegistrar;
use Proxyma\Application\Admission\ClaimSend;
use Proxyma\Application\Admission\OperationCommands;
use Proxyma\Application\Admission\ReservationService;
use Proxyma\Application\Admission\TrafficSharePolicy;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Execution\DestinationPolicy;
use Proxyma\Application\Execution\OutboundExecutor;
use Proxyma\Application\Execution\OutcomePolicyRegistry;
use Proxyma\Application\Execution\ProxyResolver;
use Proxyma\Application\Execution\WireSealer;
use Proxyma\Application\Extensions\AttemptObservers;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Application\Extensions\ExtensionRuntime;
use Proxyma\Application\Recovery\CompletionCoordinator;
use Proxyma\Application\Routing\RouteSelector;
use Proxyma\Application\Routing\SecureRandomIndex;
use Proxyma\Application\Routing\StrategyRegistry;
use Proxyma\Configuration\ProxymaOptions;
use Proxyma\Contracts\Catalog\CandidateSnapshots;
use Proxyma\Contracts\Catalog\CatalogQueries;
use Proxyma\Contracts\Execution\DestinationResolver;
use Proxyma\Contracts\Execution\OutboundClient;
use Proxyma\Contracts\Execution\PhysicalTransport;
use Proxyma\Contracts\Policy\PolicyResolver as PolicyResolverContract;
use Proxyma\Contracts\Routing\RandomIndex;
use Proxyma\Contracts\Routing\RouteSelectionStrategy;
use Proxyma\Contracts\Security\SecretResolver;
use Proxyma\Contracts\Security\TlsTrustPolicy;
use Proxyma\Domain\Outcomes\RetryAfterParser;
use Proxyma\Domain\Outcomes\TechnicalOutcomeClassifier;
use Proxyma\Domain\Panels\ExtensionKind;
use Proxyma\Infrastructure\Database\CandidateSnapshotQuery;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Http\DnsResolver;
use Proxyma\Infrastructure\Http\PhysicalHttpTransport;
use Proxyma\Infrastructure\Http\TlsTrust;
use Proxyma\Infrastructure\Telemetry\OperationsTelemetry;
use Psr\Log\LoggerInterface;

/**
 * Registers Proxyma route selection, TLS/destination trust policy, HTTP transport, outcome classifiers, observers, and execution client.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ProxymaExecutionRegistrar implements PackageRegistrar
{
    use RegistersNothingOnBoot;

    #[Override]
    public function register(Application $app): void
    {
        $app->bindIf(RandomIndex::class, SecureRandomIndex::class);

        $app->scoped(StrategyRegistry::class, static function (Application $app): StrategyRegistry {
            $strategyIds = $app->make(ProxymaOptions::class)->extensions->routeStrategies;
            $strategies = [];
            foreach ($app->make(DefinitionRegistry::class)->selectExtensions(ExtensionKind::Strategy, $strategyIds) as $definition) {
                if ($definition->implementation instanceof RouteSelectionStrategy) {
                    $strategies[] = $definition->implementation;
                }
            }

            return StrategyRegistry::withExtensions($app->make(RandomIndex::class), $strategies);
        });

        $app->bind(CandidateSnapshots::class, static fn (Application $app): CandidateSnapshotQuery => new CandidateSnapshotQuery(
            $app->make('db')->connection($app->make(ProxymaOptions::class)->connection),
            $app->make(CatalogAccess::class),
            $app->make(StrategyRegistry::class),
            $app->make(TrafficSharePolicy::class),
        ));
        $app->bind(CandidateSnapshotQuery::class, static fn (Application $app): CandidateSnapshots => $app->make(CandidateSnapshots::class));

        $app->bindIf(TlsTrustPolicy::class, static function (Application $app): TlsTrust {
            $options = $app->make(ProxymaOptions::class);

            return new TlsTrust(
                $app->make(ConnectionScope::class),
                $options->tls->originBundles,
                $options->tls->proxyBundles,
            );
        });
        $app->bindIf(PhysicalTransport::class, PhysicalHttpTransport::class);

        $app->bindIf(DnsResolver::class, static fn (Application $app): DnsResolver => new DnsResolver($app->make(ConnectionScope::class)));
        $app->bindIf(DestinationPolicy::class, static fn (Application $app): DestinationPolicy => new DestinationPolicy($app->make(DnsResolver::class)));
        $app->bindIf(DestinationResolver::class, DestinationPolicy::class);
        $app->bindIf(ProxyResolver::class, static fn (Application $app): ProxyResolver => new ProxyResolver($app->make(DnsResolver::class)));

        $app->bindIf(RouteSelector::class, static fn (Application $app): RouteSelector => new RouteSelector(
            $app->make(CandidateSnapshots::class),
            $app->make(StrategyRegistry::class),
            $app->make(ExtensionRuntime::class),
            $app->make(ProxymaOptions::class)->extensions->routeFilters,
        ));

        $app->bindIf(RetryAfterParser::class, static fn (): RetryAfterParser => new RetryAfterParser);
        $app->bindIf(TechnicalOutcomeClassifier::class, static fn (Application $app): TechnicalOutcomeClassifier => new TechnicalOutcomeClassifier(
            $app->make(RetryAfterParser::class),
        ));
        $app->singleton(OutcomePolicyRegistry::class, static fn (Application $app): OutcomePolicyRegistry => new OutcomePolicyRegistry(
            $app->make(TechnicalOutcomeClassifier::class),
            $app->make(RetryAfterParser::class),
        ));

        $app->scoped(AttemptObservers::class, static fn (Application $app): AttemptObservers => new AttemptObservers(
            $app->make(DefinitionRegistry::class),
            $app->make(ConnectionScope::class),
            $app->make(LoggerInterface::class),
            $app->make(ProxymaOptions::class)->extensions->observers,
        ));

        $app->bind(OutboundExecutor::class, static fn (Application $app): OutboundExecutor => new OutboundExecutor(
            $app->make(ConnectionScope::class),
            $app->make(CatalogQueries::class),
            $app->make(PolicyResolverContract::class),
            $app->make(DestinationResolver::class),
            $app->make(RouteSelector::class),
            $app->make(ProxyResolver::class),
            $app->make(SecretResolver::class),
            $app->make(TlsTrustPolicy::class),
            $app->make(WireSealer::class),
            $app->make(OperationCommands::class),
            $app->make(ReservationService::class),
            $app->make(ClaimSend::class),
            $app->make(PhysicalTransport::class),
            $app->make(OutcomePolicyRegistry::class),
            $app->make(CompletionCoordinator::class),
            telemetry: $app->make(OperationsTelemetry::class),
            observers: $app->make(AttemptObservers::class),
        ));
        $app->alias(OutboundExecutor::class, OutboundClient::class);
    }
}
