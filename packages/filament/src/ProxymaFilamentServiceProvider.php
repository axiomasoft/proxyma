<?php

declare(strict_types=1);

namespace Proxyma\Filament;

use Illuminate\Support\ServiceProvider;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Contracts\CatalogQueries;
use Proxyma\Contracts\TenantContextResolver;

class ProxymaFilamentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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
