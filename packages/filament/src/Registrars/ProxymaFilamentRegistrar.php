<?php

declare(strict_types=1);

namespace Proxyma\Filament\Registrars;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Filament\Contracts\FilamentSurfaceGate;
use Packman\Foundation\Contracts\PackageRegistrar;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Configuration\ProxymaOptions;
use Proxyma\Contracts\Authorization\TenantContextResolver;
use Proxyma\Contracts\Catalog\CatalogQueries;
use Proxyma\Filament\BindingEditor;
use Proxyma\Filament\CatalogBrowser;
use Proxyma\Filament\Internal\ProxymaSurfaceGate;
use Proxyma\Filament\ProxymaPlugin;
use Proxyma\Filament\Queries\OperationsQuery;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Security\ContainerTenantContextResolver;

/**
 * Registers Proxyma Filament queries, catalog browser, guarded binding editor, and plugin instance.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ProxymaFilamentRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        $app->bind(OperationsQuery::class, static fn (Application $app): OperationsQuery => new OperationsQuery(
            $app->make(ConnectionScope::class),
            $app->make(CatalogAccess::class),
            new ContainerTenantContextResolver($app),
        ));

        $app->bind(CatalogBrowser::class, static function (Application $app): CatalogBrowser {
            $connection = $app->make(ProxymaOptions::class)->connection;

            return new CatalogBrowser(
                $app->make('db')->connection($connection !== '' ? $connection : 'pgsql'),
                $app->make(CatalogAccess::class),
                $app->make(CatalogQueries::class),
                $app->make(TenantContextResolver::class),
            );
        });

        $app->bind(BindingEditor::class);
        $app->bindIf(ProxymaPlugin::class, static fn (): ProxymaPlugin => new ProxymaPlugin);
    }

    #[Override]
    public function boot(Application $app): void
    {
        $app->make('view')->addNamespace('proxyma-filament', dirname(__DIR__, 2).'/resources/views');

        if ($app->bound(FilamentSurfaceGate::class)) {
            $app->extend(FilamentSurfaceGate::class, static fn (FilamentSurfaceGate $inner, Application $app): FilamentSurfaceGate => new ProxymaSurfaceGate(
                inner: $inner,
                gate: $app->make(Gate::class),
                container: $app,
            ));
        }
    }
}
