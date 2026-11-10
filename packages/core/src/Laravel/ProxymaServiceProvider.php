<?php

declare(strict_types=1);

namespace Proxyma\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Proxyma\Application\Admission\AuthorityLocker;
use Proxyma\Application\Admission\DemandQueue;
use Proxyma\Application\Admission\OperationCommands;
use Proxyma\Application\Admission\ReservationService;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Bindings\BindingContext;
use Proxyma\Application\Bindings\DatabaseBindingCommands;
use Proxyma\Application\Bindings\DatabaseBindingQueries;
use Proxyma\Application\Catalog\DatabaseCatalogCommands;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Application\Policy\PolicyResolver;
use Proxyma\Application\Policy\SettingRegistry;
use Proxyma\Application\Policy\SettingRegistryFactory;
use Proxyma\Application\Policy\UpdatePolicy;
use Proxyma\Application\Routing\SecureRandomIndex;
use Proxyma\Application\Routing\StrategyRegistry;
use Proxyma\Contracts\Authorizer;
use Proxyma\Contracts\BindingCommands;
use Proxyma\Contracts\BindingQueries;
use Proxyma\Contracts\CandidateSnapshots;
use Proxyma\Contracts\CatalogCommands;
use Proxyma\Contracts\CatalogQueries;
use Proxyma\Contracts\OwnerAuthority;
use Proxyma\Contracts\PolicyCommands;
use Proxyma\Contracts\PolicyResolver as PolicyResolverContract;
use Proxyma\Contracts\RandomIndex;
use Proxyma\Contracts\RouteAccessChecks;
use Proxyma\Contracts\SharedRouteCommands;
use Proxyma\Contracts\TenantContextResolver;
use Proxyma\Contracts\TransactionCoordinator;
use Proxyma\Domain\Admission\QueueLimits;
use Proxyma\Domain\Policy\InvalidSettingPatch;
use Proxyma\Infrastructure\Admission\DatabaseOwnerAuthority;
use Proxyma\Infrastructure\Admission\ReservationCatalogLocks;
use Proxyma\Infrastructure\Cache\PolicyResolutionCache;
use Proxyma\Infrastructure\Database\BudgetRepository;
use Proxyma\Infrastructure\Database\CandidateSnapshotQuery;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\DatabaseCatalogQueries;
use Proxyma\Infrastructure\Database\DemandRepository;
use Proxyma\Infrastructure\Database\PermitRepository;
use Proxyma\Infrastructure\Security\ContainerAuthorizer;
use Proxyma\Infrastructure\Security\ContainerTenantContextResolver;
use Proxyma\Infrastructure\Security\DenyAuthorizer;
use Proxyma\Infrastructure\Security\UnconfiguredTenantContextResolver;

class ProxymaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/proxyma.php', 'proxyma');

        $this->app->bindIf(Authorizer::class, DenyAuthorizer::class);
        $this->app->bindIf(TenantContextResolver::class, UnconfiguredTenantContextResolver::class);
        $this->app->bind(CatalogAccess::class, static fn (Application $app): CatalogAccess => new CatalogAccess(
            new ContainerTenantContextResolver($app), new ContainerAuthorizer($app)));

        $this->app->bind(DatabaseCatalogCommands::class, static function (Application $app): DatabaseCatalogCommands {
            return new DatabaseCatalogCommands($app['db']->connection($app['config']->get('proxyma.connection', 'pgsql')), $app->make(CatalogAccess::class));
        });
        $this->app->bind(DatabaseCatalogQueries::class, static function (Application $app): DatabaseCatalogQueries {
            return new DatabaseCatalogQueries($app['db']->connection($app['config']->get('proxyma.connection', 'pgsql')), $app->make(CatalogAccess::class));
        });
        $this->app->alias(DatabaseCatalogCommands::class, CatalogCommands::class);
        $this->app->alias(DatabaseCatalogCommands::class, SharedRouteCommands::class);
        $this->app->alias(DatabaseCatalogQueries::class, CatalogQueries::class);
        $this->app->alias(DatabaseCatalogQueries::class, RouteAccessChecks::class);

        $this->app->bindIf(RandomIndex::class, SecureRandomIndex::class);
        $this->app->bind(CandidateSnapshots::class, static fn (Application $app): CandidateSnapshotQuery => new CandidateSnapshotQuery(
            $app['db']->connection($app['config']->get('proxyma.connection', 'pgsql')), $app->make(CatalogAccess::class), $app->make(StrategyRegistry::class)));

        $this->app->singleton(PolicyResolutionCache::class, static fn (): PolicyResolutionCache => new PolicyResolutionCache);

        $this->app->singleton(SettingRegistry::class, static function (Application $app): SettingRegistry {
            $configuration = $app['config']->get('proxyma.settings', []);
            if (! is_array($configuration)) {
                throw new InvalidSettingPatch;
            }

            return (new SettingRegistryFactory($app))->make($configuration);
        });
        $this->app->bind(UpdatePolicy::class, static function (Application $app): UpdatePolicy {
            return new UpdatePolicy(
                $app['db']->connection($app['config']->get('proxyma.connection', 'pgsql')),
                $app->make(CatalogAccess::class),
                $app->make(SettingRegistry::class),
                $app->make(PolicyResolutionCache::class),
            );
        });
        $this->app->alias(UpdatePolicy::class, PolicyCommands::class);

        $this->app->singleton(DefinitionRegistry::class, static fn (Application $app): DefinitionRegistry => new DefinitionRegistry($app->make(SettingRegistry::class)));
        $this->app->bind(DatabaseBindingCommands::class, static fn (Application $app): DatabaseBindingCommands => new DatabaseBindingCommands(
            $app['db']->connection($app['config']->get('proxyma.connection', 'pgsql')),
            $app->make(CatalogAccess::class), $app->make(DefinitionRegistry::class), $app->make(SettingRegistry::class), $app->make(PolicyResolutionCache::class)));
        $this->app->alias(DatabaseBindingCommands::class, BindingCommands::class);
        $this->app->bind(DatabaseBindingQueries::class, static fn (Application $app): DatabaseBindingQueries => new DatabaseBindingQueries(
            $app['db']->connection($app['config']->get('proxyma.connection', 'pgsql')),
            $app->make(BindingContext::class)));
        $this->app->alias(DatabaseBindingQueries::class, BindingQueries::class);

        $this->app->bind(PolicyResolver::class, static function (Application $app): PolicyResolver {
            return new PolicyResolver(
                db: $app['db']->connection($app['config']->get('proxyma.connection', 'pgsql')),
                access: $app->make(CatalogAccess::class),
                registry: null,
                cache: $app->make(PolicyResolutionCache::class),
                registryResolver: static fn (): SettingRegistry => $app->make(SettingRegistry::class),
                policyConfigResolver: static function () use ($app): array {
                    $cfg = $app['config']->get('proxyma.policy', []);

                    return is_array($cfg) ? $cfg : [];
                },
                definitions: $app->make(DefinitionRegistry::class),
            );
        });
        $this->app->alias(PolicyResolver::class, PolicyResolverContract::class);

        $this->app->scoped(ConnectionScope::class, static function (Application $app): ConnectionScope {
            $connection = $app['config']->get('proxyma.connection', 'pgsql');

            return new ConnectionScope($app['db']->connection(is_string($connection) && $connection !== '' ? $connection : 'pgsql'), $app['db.transactions']);
        });
        $this->app->scoped(\Proxyma\Application\Admission\TransactionCoordinator::class);
        $this->app->alias(\Proxyma\Application\Admission\TransactionCoordinator::class, TransactionCoordinator::class);
        $this->app->scoped(AuthorityLocker::class);
        $this->app->bind(DatabaseOwnerAuthority::class);
        $this->app->bindIf(OwnerAuthority::class, DatabaseOwnerAuthority::class);
        $this->app->bind(QueueLimits::class, static function (Application $app): QueueLimits {
            $cfg = $app['config']->get('proxyma.queue', []);

            return new QueueLimits(...$cfg);
        });
        $this->app->bind(DemandRepository::class);
        $this->app->bind(OperationCommands::class);
        $this->app->bind(DemandQueue::class);
        $this->app->bind(BudgetRepository::class);
        $this->app->bind(PermitRepository::class);
        $this->app->bind(ReservationCatalogLocks::class);
        $this->app->bind(ReservationService::class, static function (Application $app): ReservationService {
            return new ReservationService($app->make(\Proxyma\Application\Admission\TransactionCoordinator::class), $app->make(AuthorityLocker::class),
                $app->make(CatalogAccess::class), $app->make(CatalogQueries::class), $app->make(OwnerAuthority::class), $app->make(DefinitionRegistry::class),
                $app->make(DemandQueue::class), $app->make(DemandRepository::class), $app->make(BudgetRepository::class),
                $app->make(PermitRepository::class), $app->make(ReservationCatalogLocks::class),
                $app->make(QueueLimits::class), $app['config']->get('proxyma.extensions.budget_contributors', []), $app['config']->get('proxyma.extensions.locked_admission_guards', []));
        });
    }

    public function boot(): void
    {
        $this->app->booted(fn () => $this->app->make(DefinitionRegistry::class)->freeze());
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/proxyma.php' => $this->app->configPath('proxyma.php'),
            ], 'proxyma-config');
        }
    }
}
