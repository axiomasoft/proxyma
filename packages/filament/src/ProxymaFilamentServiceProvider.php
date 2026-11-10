<?php

declare(strict_types=1);

namespace Proxyma\Filament;

use Illuminate\Support\ServiceProvider;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Contracts\CatalogQueries;
use Proxyma\Contracts\TenantContextResolver;
use Proxyma\Filament\Queries\OperationsQuery;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Security\ContainerTenantContextResolver;

class ProxymaFilamentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OperationsQuery::class, fn ($app): OperationsQuery => new OperationsQuery(
            $app->make(ConnectionScope::class), $app->make(CatalogAccess::class), new ContainerTenantContextResolver($app),
        ));
        $this->app->bind(CatalogBrowser::class, fn ($app): CatalogBrowser => new CatalogBrowser(
            $app['db']->connection($app['config']->get('proxyma.connection', 'pgsql')),
            $app->make(CatalogAccess::class),
            $app->make(CatalogQueries::class),
            $app->make(TenantContextResolver::class),
        ));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'proxyma-filament');
    }
}
