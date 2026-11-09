<?php

declare(strict_types=1);

namespace Proxyma\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Catalog\DatabaseCatalogCommands;
use Proxyma\Contracts\Authorizer;
use Proxyma\Contracts\CatalogCommands;
use Proxyma\Contracts\CatalogQueries;
use Proxyma\Contracts\RouteAccessChecks;
use Proxyma\Contracts\SharedRouteCommands;
use Proxyma\Contracts\TenantContextResolver;
use Proxyma\Contracts\TransactionCoordinator;
use Proxyma\Infrastructure\Database\DatabaseCatalogQueries;
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

        $this->app->bind(TransactionCoordinator::class, static function (Application $app): TransactionCoordinator {
            $connection = $app['config']->get('proxyma.connection', 'pgsql');

            return new DatabaseTransactionCoordinator(is_string($connection) && $connection !== '' ? $connection : 'pgsql');
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/proxyma.php' => $this->app->configPath('proxyma.php'),
            ], 'proxyma-config');
        }
    }
}
