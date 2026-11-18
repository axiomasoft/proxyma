<?php

declare(strict_types=1);

namespace Proxyma\Application\Extensions;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Api;
use Packman\Plugins\Contracts\Contributions\Extensions;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Data\Plugin\PluginDescription;
use Proxyma\Contracts\Admission\LockedAdmissionGuard;
use Proxyma\Contracts\Admission\SqlConnectionParticipant;
use Proxyma\Contracts\Execution\OutboundObserver;
use Proxyma\Contracts\Execution\OutcomePolicy;
use Proxyma\Contracts\Extensions\ExtensionProvider;
use Proxyma\Contracts\Policy\PolicyDefinitionProvider;
use Proxyma\Contracts\Routing\RouteFilter;
use Proxyma\Contracts\Routing\RouteSelectionStrategy;
use Proxyma\Domain\Panels\DefinitionError;
use Proxyma\Domain\Panels\ExtensionDefinition;
use Proxyma\Domain\Panels\ExtensionKind;
use Throwable;

#[Api(since: '1.0')]
final readonly class ExtensionConfiguration
{
    public const array SELECTIONS = [
        'route_strategies' => ExtensionKind::Strategy,
        'outcome_policies' => ExtensionKind::OutcomePolicy,
        'route_filters' => ExtensionKind::RouteFilter,
        'budget_contributors' => ExtensionKind::BudgetScopeContributor,
        'locked_admission_guards' => ExtensionKind::LockedAdmissionGuard,
        'observers' => ExtensionKind::Observer,
        'policy_definitions' => ExtensionKind::PolicyDefinitions,
    ];

    public function __construct(
        private Container $container,
        private ?Extensions $extensions = null,
    ) {}

    /** @param array<string, mixed> $config */
    public function register(DefinitionRegistry $registry, array $config): void
    {
        $this->serializable($config);

        if (array_diff(array_keys($config), ['approved_providers', 'providers', 'policy_definitions', ...array_keys(self::SELECTIONS)]) !== []) {
            throw new DefinitionError('Unknown extension configuration.');
        }

        $approved = $config['approved_providers'] ?? [];
        $providers = $config['providers'] ?? [];

        if (! is_array($approved) || ! is_array($providers) || ! array_is_list($providers) || count(array_unique($providers, SORT_REGULAR)) !== count($providers)) {
            throw new DefinitionError('Invalid extension providers.');
        }

        foreach ($approved as $id => $class) {
            if (! is_string($id) || ! is_string($class) || ! is_subclass_of($class, ExtensionPlugin::class)) {
                throw new DefinitionError('Only approved Packman ExtensionPlugin classes are allowed.');
            }
        }

        /** @var list<class-string<ExtensionPlugin>> $requestedPlugins */
        $requestedPlugins = [];

        foreach ($providers as $id) {
            if (! is_string($id) || ! isset($approved[$id])) {
                throw new DefinitionError('Missing approved extension provider.');
            }

            /** @var class-string<ExtensionPlugin> $pluginClass */
            $pluginClass = $approved[$id];
            $requestedPlugins[] = $pluginClass;
        }

        if ($requestedPlugins !== []) {
            $this->enqueuePlugins($requestedPlugins);
        }

        $extensions = $this->resolveExtensions($requestedPlugins !== []);

        if ($extensions === null) {
            return;
        }

        try {
            foreach ($extensions->resolve(ExtensionProvider::class) as $provider) {
                $provider->register($registry);
            }

            /** @var list<array{0: class-string, 1: ExtensionKind, 2: bool}> $slotMappings */
            $slotMappings = [
                [RouteFilter::class, ExtensionKind::RouteFilter, false],
                [LockedAdmissionGuard::class, ExtensionKind::LockedAdmissionGuard, false],
                [RouteSelectionStrategy::class, ExtensionKind::Strategy, true],
                [OutcomePolicy::class, ExtensionKind::OutcomePolicy, true],
                [OutboundObserver::class, ExtensionKind::Observer, false],
                [PolicyDefinitionProvider::class, ExtensionKind::PolicyDefinitions, true],
            ];

            foreach ($slotMappings as [$slotClass, $kind, $keyed]) {
                $this->hydrateSlot($registry, $extensions, $slotClass, $kind, $keyed);
            }
        } catch (DefinitionError $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new DefinitionError('Packman extension slot compilation failed: '.$error->getMessage(), 0, $error);
        }
    }

    /** @param array<string, mixed> $config */
    public function validate(DefinitionRegistry $registry, array $config): void
    {
        foreach (self::SELECTIONS as $key => $kind) {
            $ids = $config[$key] ?? [];

            if (! is_array($ids) || ! array_is_list($ids)) {
                throw new DefinitionError('Extension selections must be lists of registered IDs.');
            }

            $stringIds = [];

            foreach ($ids as $id) {
                if (! is_string($id)) {
                    throw new DefinitionError('Extension selections must be lists of registered IDs.');
                }

                $stringIds[] = $id;
            }

            $registry->selectExtensions($kind, $stringIds);
        }
    }

    /** @param list<class-string<ExtensionPlugin>> $plugins */
    private function enqueuePlugins(array $plugins): void
    {
        if (! $this->container->bound(Extensions::class)) {
            throw new DefinitionError('Packman plugin registry is required for configured extensions.');
        }

        if ($this->container->resolved(Extensions::class) || $this->container->resolved(PluginInspector::class)) {
            if ($this->container->bound(PluginInspector::class)) {
                $inspector = $this->container->make(PluginInspector::class);
                $activeClasses = array_map(
                    static fn (PluginDescription $description): string => $description->class,
                    $inspector->describe(),
                );

                if (array_diff($plugins, $activeClasses) === []) {
                    return;
                }
            }

            throw new DefinitionError('Extension plugins must be registered before Packman plugins freeze.');
        }

        if (! $this->container->bound(Repository::class)) {
            throw new DefinitionError('Configuration repository is required to register Packman plugins.');
        }

        $config = $this->container->make(Repository::class);
        $raw = $config->get('packman.plugins', []);
        $existing = is_array($raw) ? array_values(array_filter($raw, is_string(...))) : [];
        $config->set('packman.plugins', array_values(array_unique([...$existing, ...$plugins])));
    }

    private function resolveExtensions(bool $required): ?Extensions
    {
        if ($this->extensions !== null) {
            return $this->extensions;
        }

        if (! $this->container->bound(Extensions::class)) {
            if ($required) {
                throw new DefinitionError('Packman plugin registry is required for configured extensions.');
            }

            return null;
        }

        try {
            return $this->container->make(Extensions::class);
        } catch (Throwable $error) {
            throw new DefinitionError('Packman plugin graph validation failed: '.$error->getMessage(), 0, $error);
        }
    }

    /** @param class-string $slotClass */
    private function hydrateSlot(
        DefinitionRegistry $registry,
        Extensions $extensions,
        string $slotClass,
        ExtensionKind $kind,
        bool $keyed,
    ): void {
        $contributions = $extensions->contributions($slotClass);

        if ($contributions === []) {
            return;
        }

        $instances = $extensions->resolve($slotClass);

        foreach ($contributions as $index => $contribution) {
            $id = $keyed ? ($contribution->key ?? $contribution->id) : $contribution->id;

            if ($registry->hasExtension($id)) {
                continue;
            }

            $instance = $instances[$index] ?? null;

            if (! $instance instanceof RouteFilter
                && ! $instance instanceof LockedAdmissionGuard
                && ! $instance instanceof RouteSelectionStrategy
                && ! $instance instanceof OutcomePolicy
                && ! $instance instanceof OutboundObserver
                && ! $instance instanceof PolicyDefinitionProvider) {
                throw new DefinitionError('Invalid typed extension implementation in Packman slot.');
            }

            $tier = null;

            if ($kind === ExtensionKind::LockedAdmissionGuard) {
                if (! $instance instanceof LockedAdmissionGuard || ! $instance instanceof SqlConnectionParticipant) {
                    throw new DefinitionError('Guard requires a matching adapter_guard tier and SQL participant binding.');
                }

                $tier = $instance->tier();
            }

            $registry->registerExtension(new ExtensionDefinition(
                id: $id,
                kind: $kind,
                implementation: $instance,
                priority: $contribution->priority,
                tier: $tier,
            ));
        }
    }

    private function serializable(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->serializable($item);
            }

            return;
        }

        if (! is_scalar($value) && $value !== null) {
            throw new DefinitionError('Extension config must contain only serializable scalars and class strings.');
        }
    }
}
