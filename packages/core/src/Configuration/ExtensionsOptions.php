<?php

declare(strict_types=1);

namespace Proxyma\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Approved extension provider registry and active selection lists.
 */
#[Api(since: '1.0')]
final readonly class ExtensionsOptions implements OptionsFactory
{
    /**
     * @param  array<string, string>  $approvedProviders
     * @param  list<string>  $providers
     * @param  list<string>  $policyDefinitions
     * @param  list<string>  $routeStrategies
     * @param  list<string>  $outcomePolicies
     * @param  list<string>  $routeFilters
     * @param  list<string>  $budgetContributors
     * @param  list<string>  $lockedAdmissionGuards
     * @param  list<string>  $observers
     */
    public function __construct(
        public array $approvedProviders = [],
        public array $providers = [],
        public array $policyDefinitions = [],
        public array $routeStrategies = [],
        public array $outcomePolicies = [],
        public array $routeFilters = [],
        public array $budgetContributors = [],
        public array $lockedAdmissionGuards = [],
        public array $observers = [],
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'proxyma.extensions'): self
    {
        $validator = new ConfigurationValidator;
        $allowed = [
            'approved_providers',
            'providers',
            'policy_definitions',
            'route_strategies',
            'outcome_policies',
            'route_filters',
            'budget_contributors',
            'locked_admission_guards',
            'observers',
        ];
        $validator->rejectUnknownKeys($config, $allowed, $path);

        $approved = [];
        $rawApproved = $config['approved_providers'] ?? [];

        if (! is_array($rawApproved)) {
            $validator->add($path.'.approved_providers', 'must be an associative map of provider id => class-string');
        } else {
            foreach ($rawApproved as $id => $class) {
                if (! is_string($id) || $id === '' || ! is_string($class) || $class === '') {
                    $validator->add($path.'.approved_providers', 'must map non-empty string IDs to provider class strings');

                    continue;
                }

                $approved[$id] = $class;
            }
        }

        $providers = self::stringList($validator, $path.'.providers', $config['providers'] ?? [], requireUnique: true);
        $policyDefinitions = self::stringList($validator, $path.'.policy_definitions', $config['policy_definitions'] ?? []);
        $routeStrategies = self::stringList($validator, $path.'.route_strategies', $config['route_strategies'] ?? []);
        $outcomePolicies = self::stringList($validator, $path.'.outcome_policies', $config['outcome_policies'] ?? []);
        $routeFilters = self::stringList($validator, $path.'.route_filters', $config['route_filters'] ?? []);
        $budgetContributors = self::stringList($validator, $path.'.budget_contributors', $config['budget_contributors'] ?? []);
        $lockedAdmissionGuards = self::stringList($validator, $path.'.locked_admission_guards', $config['locked_admission_guards'] ?? []);
        $observers = self::stringList($validator, $path.'.observers', $config['observers'] ?? []);

        $validator->failIfAny();

        return new self(
            approvedProviders: $approved,
            providers: $providers,
            policyDefinitions: $policyDefinitions,
            routeStrategies: $routeStrategies,
            outcomePolicies: $outcomePolicies,
            routeFilters: $routeFilters,
            budgetContributors: $budgetContributors,
            lockedAdmissionGuards: $lockedAdmissionGuards,
            observers: $observers,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'approved_providers' => $this->approvedProviders,
            'providers' => $this->providers,
            'policy_definitions' => $this->policyDefinitions,
            'route_strategies' => $this->routeStrategies,
            'outcome_policies' => $this->outcomePolicies,
            'route_filters' => $this->routeFilters,
            'budget_contributors' => $this->budgetContributors,
            'locked_admission_guards' => $this->lockedAdmissionGuards,
            'observers' => $this->observers,
        ];
    }

    /** @return list<string> */
    private static function stringList(
        ConfigurationValidator $validator,
        string $path,
        mixed $value,
        bool $requireUnique = false,
    ): array {
        if (! is_array($value) || ! array_is_list($value)) {
            $validator->add($path, 'must be a list of extension identifier strings');

            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                $validator->add($path, 'must contain only non-empty strings');

                return [];
            }

            $items[] = $item;
        }

        if ($requireUnique && count(array_unique($items)) !== count($items)) {
            $validator->add($path, 'must not contain duplicate provider identifiers');
        }

        return $items;
    }
}
