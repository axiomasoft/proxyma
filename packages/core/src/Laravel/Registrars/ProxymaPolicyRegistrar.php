<?php

declare(strict_types=1);

namespace Proxyma\Laravel\Registrars;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Contracts\PackageRegistrar;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Bindings\BindingContext;
use Proxyma\Application\Bindings\BindingEditVersion;
use Proxyma\Application\Bindings\DatabaseBindingCommands;
use Proxyma\Application\Bindings\DatabaseBindingQueries;
use Proxyma\Application\Execution\OutcomePolicyRegistry;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Application\Extensions\ExtensionConfiguration;
use Proxyma\Application\Policy\PolicyResolver;
use Proxyma\Application\Policy\SettingRegistry;
use Proxyma\Application\Policy\SettingRegistryFactory;
use Proxyma\Application\Policy\UpdatePolicy;
use Proxyma\Application\Routing\StrategyRegistry;
use Proxyma\Configuration\ProxymaOptions;
use Proxyma\Contracts\Bindings\BindingCommands;
use Proxyma\Contracts\Bindings\BindingQueries;
use Proxyma\Contracts\Execution\OutcomePolicy;
use Proxyma\Contracts\Policy\PolicyCommands;
use Proxyma\Contracts\Policy\PolicyResolver as PolicyResolverContract;
use Proxyma\Domain\Panels\ExtensionKind;
use Proxyma\Infrastructure\Cache\PolicyResolutionCache;

/**
 * Registers Proxyma policy resolution, setting definitions, extension definitions, and binding management.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ProxymaPolicyRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        $app->singleton(PolicyResolutionCache::class, static fn (): PolicyResolutionCache => new PolicyResolutionCache);

        $app->singleton(SettingRegistry::class, static function (Application $app): SettingRegistry {
            $options = $app->make(ProxymaOptions::class);

            return new SettingRegistryFactory($app)->make(
                $options->settings->toArray(),
                $options->extensions->routeStrategies,
            );
        });

        $app->singleton(DefinitionRegistry::class, static fn (Application $app): DefinitionRegistry => new DefinitionRegistry(
            $app->make(SettingRegistry::class),
        ));

        $app->bind(BindingEditVersion::class, static function (Application $app): BindingEditVersion {
            $options = $app->make(ProxymaOptions::class);

            return new BindingEditVersion(
                self::connection($app, $options),
                $app->make(DefinitionRegistry::class),
                $app->make(SettingRegistry::class),
                $options->policy,
            );
        });

        $app->bind(UpdatePolicy::class, static fn (Application $app): UpdatePolicy => new UpdatePolicy(
            self::connection($app, $app->make(ProxymaOptions::class)),
            $app->make(CatalogAccess::class),
            $app->make(SettingRegistry::class),
            $app->make(PolicyResolutionCache::class),
        ));
        $app->alias(UpdatePolicy::class, PolicyCommands::class);

        $app->bind(DatabaseBindingCommands::class, static function (Application $app): DatabaseBindingCommands {
            $options = $app->make(ProxymaOptions::class);

            return new DatabaseBindingCommands(
                self::connection($app, $options),
                $app->make(CatalogAccess::class),
                $app->make(DefinitionRegistry::class),
                $app->make(SettingRegistry::class),
                $app->make(PolicyResolutionCache::class),
                $options->policy,
            );
        });
        $app->alias(DatabaseBindingCommands::class, BindingCommands::class);

        $app->bind(DatabaseBindingQueries::class, static fn (Application $app): DatabaseBindingQueries => new DatabaseBindingQueries(
            self::connection($app, $app->make(ProxymaOptions::class)),
            $app->make(BindingContext::class),
        ));
        $app->alias(DatabaseBindingQueries::class, BindingQueries::class);

        $app->bind(PolicyResolver::class, static function (Application $app): PolicyResolver {
            $options = $app->make(ProxymaOptions::class);

            return new PolicyResolver(
                db: self::connection($app, $options),
                access: $app->make(CatalogAccess::class),
                cache: $app->make(PolicyResolutionCache::class),
                registryResolver: static fn (): SettingRegistry => $app->make(SettingRegistry::class),
                policyConfigResolver: static fn (): array => $app->make(ProxymaOptions::class)->policy->toArray(),
                defaultPanelKey: $options->policy->panelKey ?? 'default',
                definitions: $app->make(DefinitionRegistry::class),
            );
        });
        $app->alias(PolicyResolver::class, PolicyResolverContract::class);
    }

    #[Override]
    public function boot(Application $app): void
    {
        $app->booted(static function () use ($app): void {
            $options = $app->make(ProxymaOptions::class);
            $extensions = $options->extensions;
            $rawExtensions = $extensions->toArray();

            $registry = $app->make(DefinitionRegistry::class);
            $configuration = $app->make(ExtensionConfiguration::class);
            $configuration->register($registry, $rawExtensions);
            $registry->freeze();
            $configuration->validate($registry, $rawExtensions);

            $outcomes = $app->make(OutcomePolicyRegistry::class);

            foreach ($registry->selectExtensions(ExtensionKind::OutcomePolicy, $extensions->outcomePolicies) as $definition) {
                if ($definition->implementation instanceof OutcomePolicy) {
                    $outcomes->register($definition->id, $definition->implementation, priority: $definition->priority);
                }
            }

            $outcomes->freeze();
            $app->make(StrategyRegistry::class);
        });
    }

    private static function connection(Application $app, ProxymaOptions $options): Connection
    {
        return $app->make('db')->connection($options->connection !== '' ? $options->connection : 'pgsql');
    }
}
