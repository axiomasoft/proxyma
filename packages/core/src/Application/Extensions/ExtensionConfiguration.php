<?php

declare(strict_types=1);

namespace Proxyma\Application\Extensions;

use Illuminate\Contracts\Container\Container;
use Proxyma\Contracts\ExtensionProvider;
use Proxyma\Domain\Panels\DefinitionError;
use Proxyma\Domain\Panels\ExtensionKind;

final readonly class ExtensionConfiguration
{
    public const SELECTIONS = ['route_strategies' => ExtensionKind::Strategy, 'outcome_policies' => ExtensionKind::OutcomePolicy,
        'route_filters' => ExtensionKind::RouteFilter, 'budget_contributors' => ExtensionKind::BudgetScopeContributor,
        'locked_admission_guards' => ExtensionKind::LockedAdmissionGuard, 'observers' => ExtensionKind::Observer,
        'policy_definitions' => ExtensionKind::PolicyDefinitions];

    public function __construct(private Container $container) {}

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
            if (! is_string($id) || ! is_string($class) || ! is_subclass_of($class, ExtensionProvider::class)) {
                throw new DefinitionError('Only approved typed extension providers are allowed.');
            }
        }
        foreach ($providers as $id) {
            if (! is_string($id) || ! isset($approved[$id])) {
                throw new DefinitionError('Missing approved extension provider.');
            }
            $this->container->make($approved[$id])->register($registry);
        }
    }

    public function validate(DefinitionRegistry $registry, array $config): void
    {
        foreach (self::SELECTIONS as $key => $kind) {
            $ids = $config[$key] ?? [];
            if (! is_array($ids)) {
                throw new DefinitionError('Extension selections must be lists of registered IDs.');
            }
            $registry->selectExtensions($kind, $ids);
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
