<?php

declare(strict_types=1);

namespace Proxyma\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Proxyma\Application\Catalog\DatabaseCatalogCommands;
use Proxyma\Contracts\Authorizer;
use Proxyma\Contracts\CatalogCommands;
use Proxyma\Contracts\CatalogQueries;
use Proxyma\Contracts\TransactionCoordinator;
use Proxyma\Infrastructure\Database\DatabaseCatalogQueries;

class ProxymaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/proxyma.php', 'proxyma');

        $this->app->bind(CatalogCommands::class, static function (Application $app): CatalogCommands {
            return new DatabaseCatalogCommands($app['db']->connection($app['config']->get('proxyma.connection', 'pgsql')), $app->make(Authorizer::class));
        });
        $this->app->bind(CatalogQueries::class, static function (Application $app): CatalogQueries {
            return new DatabaseCatalogQueries($app['db']->connection($app['config']->get('proxyma.connection', 'pgsql')));
        });

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
