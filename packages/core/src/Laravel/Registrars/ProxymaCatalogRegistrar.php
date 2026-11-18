<?php

declare(strict_types=1);

namespace Proxyma\Laravel\Registrars;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Data\TablePrefix;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Config\Laravel\ConfigSectionRegistration;
use Packman\Extensions\Laravel\ExtensionFacetRegistrar;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Panels\Contracts\Registry\PanelRegistry;
use Packman\Panels\Laravel\PanelExtensionBridge;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Data\Plugin\PluginDescription;
use Packman\Plugins\Exceptions\Registry\RegistryFrozenException;
use Packman\Plugins\Laravel\ExtensionRegistrar;
use Packman\Schema\Laravel\SchemaExtensions;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Catalog\DatabaseCatalogCommands;
use Proxyma\Application\Catalog\RotateCredentials;
use Proxyma\Application\Diagnostics\Doctor;
use Proxyma\Configuration\ProxymaOptions;
use Proxyma\Contracts\Authorization\Authorizer;
use Proxyma\Contracts\Authorization\TenantContextResolver;
use Proxyma\Contracts\Catalog\CatalogCommands;
use Proxyma\Contracts\Catalog\CatalogMutationFence;
use Proxyma\Contracts\Catalog\CatalogQueries;
use Proxyma\Contracts\Catalog\RouteAccessChecks;
use Proxyma\Contracts\Catalog\SharedRouteCommands;
use Proxyma\Contracts\Health\DiagnosticsQueries;
use Proxyma\Contracts\Security\SecretResolver;
use Proxyma\Diagnostics\ProxymaDoctorCheck;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\DatabaseCatalogQueries;
use Proxyma\Infrastructure\Database\DatabaseDiagnosticsQueries;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Secrets\EncryptedSecretStore;
use Proxyma\Infrastructure\Security\ContainerAuthorizer;
use Proxyma\Infrastructure\Security\ContainerTenantContextResolver;
use Proxyma\Infrastructure\Security\DenyAuthorizer;
use Proxyma\Infrastructure\Security\StandaloneCatalogMutationFence;
use Proxyma\Infrastructure\Security\UnconfiguredTenantContextResolver;
use Proxyma\Laravel\Console\DiagnoseCommand;
use Proxyma\Laravel\Console\DoctorCommand;
use Proxyma\Laravel\Console\HealthResetCommand;
use Proxyma\Laravel\Console\MetricsCommand;
use Proxyma\Laravel\Console\ProbeCommand;
use Proxyma\Laravel\Console\PruneCommand;
use Proxyma\Laravel\Console\ReconcileCommand;
use Proxyma\Laravel\Console\TransportDoctorCommand;
use Proxyma\Plugins\ProxymaCorePlugin;
use WeakMap;
use WeakReference;

/**
 * Registers Proxyma's core extension plugin, compiled options, schema ownership, diagnostics, catalog, and security ports.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ProxymaCatalogRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        if (class_exists(ExtensionRegistrar::class) && Container::getInstance() === $app) {
            try {
                ExtensionRegistrar::builtIn(ProxymaCorePlugin::class);
            } catch (RegistryFrozenException $exception) {
                if (! $app->bound(PluginInspector::class)
                    || ! array_any(
                        $app->make(PluginInspector::class)->describe(),
                        static fn (PluginDescription $plugin): bool => $plugin->class === ProxymaCorePlugin::class,
                    )
                ) {
                    throw $exception;
                }
            }
        }

        $app->singletonIf(ProxymaCorePlugin::class);

        $app->singleton(ProxymaDoctorCheck::class, static fn (Application $app): ProxymaDoctorCheck => new ProxymaDoctorCheck(
            config: $app->make(Repository::class),
            queries: $app->make(DiagnosticsQueries::class),
            optionsResolver: static fn (): ProxymaOptions => $app->make(ProxymaOptions::class),
        ));

        ExtensionFacetRegistrar::register(
            $app,
            ProxymaCorePlugin::class,
            dirname(__DIR__, 3).'/composer.json',
        );

        $weakApp = WeakReference::create($app);
        PackageSchema::resolvePrefixUsing(static function () use ($weakApp): string {
            $container = $weakApp->get();

            if (! $container instanceof Application) {
                return 'pxm_';
            }

            if ($container->resolved(ProxymaOptions::class)) {
                try {
                    return $container->make(ProxymaOptions::class)->tablePrefix->value();
                } catch (InvalidConfiguration) {
                    // Diagnostics still need table names when another configuration section is invalid.
                }
            }

            if ($container->bound(Repository::class)) {
                $raw = $container->make(Repository::class)->get('proxyma.table_prefix', 'pxm_');

                return is_string($raw) ? TablePrefix::fromArrayValue($raw, 'proxyma.table_prefix')->value() : 'pxm_';
            }

            return 'pxm_';
        });

        /** @var WeakMap<Application, true>|null $ownedSections */
        static $ownedSections = null;

        if (! $ownedSections instanceof WeakMap) {
            /** @var WeakMap<Application, true> $map */
            $map = new WeakMap;
            $ownedSections = $map;
        }

        if (! isset($ownedSections[$app])) {
            $ownedSections[$app] = true;
            ConfigSectionRegistration::own($app, 'proxyma', static function (array $section): void {
                ProxymaOptions::fromArray($section, 'proxyma');
            });
        }

        SchemaExtensions::register($app, ProxymaCorePlugin::class, 'axiomasoft.proxyma');

        if ($app->bound(PanelRegistry::class)) {
            PanelExtensionBridge::registerExtension($app, $app->make(ProxymaCorePlugin::class));
        } else {
            $app->afterResolving(PanelRegistry::class, static function (mixed $_, Application $app): void {
                PanelExtensionBridge::registerExtension($app, $app->make(ProxymaCorePlugin::class));
            });
        }

        $app->bindIf(Authorizer::class, DenyAuthorizer::class);
        $app->bindIf(CatalogMutationFence::class, StandaloneCatalogMutationFence::class);
        $app->bindIf(TenantContextResolver::class, UnconfiguredTenantContextResolver::class);
        $app->bind(CatalogAccess::class, static fn (Application $app): CatalogAccess => new CatalogAccess(
            new ContainerTenantContextResolver($app),
            new ContainerAuthorizer($app),
        ));

        $app->scoped(ConnectionScope::class, static fn (Application $app): ConnectionScope => new ConnectionScope(
            self::connection($app),
            $app->make('db.transactions'),
        ));

        $app->bind(DatabaseCatalogCommands::class, static fn (Application $app): DatabaseCatalogCommands => new DatabaseCatalogCommands(
            self::connection($app),
            $app->make(CatalogAccess::class),
        ));
        $app->bind(DatabaseCatalogQueries::class, static fn (Application $app): DatabaseCatalogQueries => new DatabaseCatalogQueries(
            self::connection($app),
            $app->make(CatalogAccess::class),
        ));
        $app->alias(DatabaseCatalogCommands::class, CatalogCommands::class);
        $app->alias(DatabaseCatalogCommands::class, SharedRouteCommands::class);
        $app->alias(DatabaseCatalogQueries::class, CatalogQueries::class);
        $app->alias(DatabaseCatalogQueries::class, RouteAccessChecks::class);

        $app->bind(DiagnosticsQueries::class, DatabaseDiagnosticsQueries::class);
        $app->bind(Doctor::class, static fn (Application $app): Doctor => new Doctor(
            queries: $app->make(DiagnosticsQueries::class),
            optionsResolver: static fn (): ProxymaOptions => $app->make(ProxymaOptions::class),
            check: $app->make(ProxymaDoctorCheck::class),
        ));

        $app->bind(EncryptedSecretStore::class, static fn (Application $app): EncryptedSecretStore => new EncryptedSecretStore(
            $app->make(ConnectionScope::class),
            $app->make(Encrypter::class),
            $app->make(CatalogAccess::class),
            new ContainerTenantContextResolver($app),
        ));
        $app->bindIf(SecretResolver::class, EncryptedSecretStore::class);
        $app->bind(RotateCredentials::class);
    }

    #[Override]
    public function boot(Application $app): void
    {
        if (! $app->runningInConsole()) {
            return;
        }

        \Illuminate\Console\Application::starting(static function (\Illuminate\Console\Application $artisan): void {
            $artisan->resolveCommands([
                ReconcileCommand::class,
                TransportDoctorCommand::class,
                DoctorCommand::class,
                DiagnoseCommand::class,
                PruneCommand::class,
                MetricsCommand::class,
                HealthResetCommand::class,
                ProbeCommand::class,
            ]);
        });
    }

    private static function connection(Application $app): Connection
    {
        $name = $app->make(ProxymaOptions::class)->connection;

        return $app->make('db')->connection($name !== '' ? $name : 'pgsql');
    }
}
