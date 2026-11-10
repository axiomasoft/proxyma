<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use InvalidArgumentException;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Catalog\RouteProfile;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\Uuid;

final readonly class EffectivePolicy
{
    /** @var ?list<string> */
    public ?array $allowedProfileKeys;

    /** @var list<string> */
    public array $deniedRoutes;

    /** @var ?list<string> */
    public ?array $allowedDestinations;

    /** @var array<string, BudgetConstraint> */
    public array $budgets;

    /** @var array<string, mixed> */
    public array $values;

    /** @var list<string> */
    public array $disabledSettings;

    /** @var array<string, int> */
    public array $layerRevisions;

    /** @var list<string> */
    public array $appliedLayers;

    /** @var array<string, FieldExplanation> */
    public array $explanations;

    /**
     * @param  ?list<string>  $allowedProfileKeys
     * @param  list<string>  $deniedRoutes
     * @param  ?list<string>  $allowedDestinations
     * @param  array<string, BudgetConstraint>|list<BudgetConstraint>  $budgets
     * @param  array<string, mixed>  $values
     * @param  list<string>  $disabledSettings
     * @param  array<string, int>  $layerRevisions
     * @param  list<string>  $appliedLayers
     * @param  array<string, FieldExplanation>  $explanations
     */
    public function __construct(
        public TenantKey $tenantKey,
        public SubjectChain $subjectChain,
        public Uuid $targetId,
        public string $destinationPolicyKey,
        public string $trafficKey,
        public ?Uuid $routeProfileId,
        public ?string $routeProfileKey,
        public ?int $routeProfileRevision,
        ?array $allowedProfileKeys,
        public RequestTimeoutPolicy $timeouts,
        public bool $retryDelaysDisabled,
        public bool $allowDirectRoute,
        public string $strategyKey,
        public string $outcomePolicyKey,
        array $deniedRoutes,
        ?array $allowedDestinations,
        array $budgets,
        array $values,
        array $disabledSettings,
        array $layerRevisions,
        array $appliedLayers,
        array $explanations,
        public int $effectiveRevision,
        public ContentHash $policyHash,
        public int $schemaVersion = 1,
        public array $authorityVersions = [],
    ) {
        if (! $tenantKey->equals($subjectChain->tenant)) {
            throw new InvalidArgumentException('EffectivePolicy tenantKey must match SubjectChain tenant.');
        }

        if ($effectiveRevision < 1 || $schemaVersion !== 1 || $trafficKey === '') {
            throw new InvalidArgumentException('EffectivePolicy requires positive effectiveRevision, schemaVersion 1, and non-empty trafficKey.');
        }

        $this->allowedProfileKeys = $allowedProfileKeys === null ? null : array_values(array_unique($allowedProfileKeys));
        $this->deniedRoutes = array_values(array_unique($deniedRoutes));
        $this->allowedDestinations = $allowedDestinations === null ? null : array_values(array_unique($allowedDestinations));

        $mergedBudgets = [];
        foreach ($budgets as $budget) {
            $mergedBudgets[$budget->scopeKey] = isset($mergedBudgets[$budget->scopeKey])
                ? $mergedBudgets[$budget->scopeKey]->intersect($budget)
                : $budget;
        }
        ksort($mergedBudgets, SORT_STRING);
        $this->budgets = $mergedBudgets;

        ksort($values, SORT_STRING);
        $this->values = $values;

        $disabled = array_values(array_unique($disabledSettings));
        sort($disabled, SORT_STRING);
        $this->disabledSettings = $disabled;

        ksort($layerRevisions, SORT_STRING);
        $this->layerRevisions = $layerRevisions;

        $this->appliedLayers = $appliedLayers;

        ksort($explanations, SORT_STRING);
        $this->explanations = $explanations;
    }

    public function value(string $key): mixed
    {
        if (! array_key_exists($key, $this->values)) {
            throw new InvalidArgumentException("Unknown or redacted setting key '{$key}'.");
        }

        return $this->values[$key];
    }

    public function withAuthorityVersions(array $versions): self
    {
        return new self(...[...get_object_vars($this), 'authorityVersions' => $versions]);
    }

    public function isDisabled(string $key): bool
    {
        return in_array($key, $this->disabledSettings, true);
    }

    public function isRouteDenied(Route|Uuid|string $route): bool
    {
        if ($route instanceof Route) {
            return in_array($route->routeKey, $this->deniedRoutes, true)
                || in_array($route->id->value, $this->deniedRoutes, true);
        }

        $identifier = $route instanceof Uuid ? $route->value : $route;

        return in_array($identifier, $this->deniedRoutes, true);
    }

    public function isDestinationAllowed(string $destinationKey): bool
    {
        if ($this->allowedDestinations === null) {
            return true;
        }

        return in_array($destinationKey, $this->allowedDestinations, true);
    }

    public function isProfileAllowed(string $profileKey): bool
    {
        if ($this->allowedProfileKeys === null) {
            return true;
        }

        return in_array($profileKey, $this->allowedProfileKeys, true);
    }

    public function allowsRoute(Route $route, RouteProfile $profile): bool
    {
        if ($this->routeProfileId !== null && ! $profile->id->equals($this->routeProfileId)) {
            return false;
        }

        if ($this->routeProfileKey !== null && $profile->profileKey !== $this->routeProfileKey) {
            return false;
        }

        if (! $this->isProfileAllowed($profile->profileKey)) {
            return false;
        }

        if (! $this->isDestinationAllowed($this->destinationPolicyKey)) {
            return false;
        }

        if ($this->isRouteDenied($route)) {
            return false;
        }

        return $profile->allowsRoute($route, directAllowedByPolicy: $this->allowDirectRoute);
    }

    public function snapshot(): EffectivePolicySnapshot
    {
        return new EffectivePolicySnapshot(
            tenantKey: $this->tenantKey,
            targetId: $this->targetId,
            routeProfileId: $this->routeProfileId,
            timeouts: $this->timeouts,
            allowDirectRoute: $this->allowDirectRoute,
            outcomePolicyKey: $this->outcomePolicyKey,
            effectiveRevision: $this->effectiveRevision,
            policyHash: $this->policyHash,
            layerRevisions: $this->layerRevisions,
        );
    }

    public function pin(): PinnedPolicySnapshot
    {
        return new PinnedPolicySnapshot(
            tenantKey: $this->tenantKey,
            targetId: $this->targetId,
            destinationPolicyKey: $this->destinationPolicyKey,
            routeProfileId: $this->routeProfileId,
            routeProfileKey: $this->routeProfileKey,
            routeProfileRevision: $this->routeProfileRevision,
            effectiveRevision: $this->effectiveRevision,
            policyHash: $this->policyHash,
            layerRevisions: $this->layerRevisions,
            strategyKey: $this->strategyKey,
            outcomePolicyKey: $this->outcomePolicyKey,
            timeouts: $this->timeouts,
            retryDelaysDisabled: $this->retryDelaysDisabled,
            allowDirectRoute: $this->allowDirectRoute,
            deniedRoutes: $this->deniedRoutes,
            allowedDestinations: $this->allowedDestinations,
            budgets: array_values($this->budgets),
            schemaVersion: $this->schemaVersion,
        );
    }

    public function explanation(): PolicyExplanation
    {
        $fieldSources = [];
        foreach ($this->explanations as $key => $fieldExplanation) {
            if ($fieldExplanation->sensitive) {
                continue;
            }
            $fieldSources[$key] = "{$fieldExplanation->sourceScope}@r{$fieldExplanation->sourceRevision}";
        }

        return new PolicyExplanation(
            snapshot: $this->snapshot(),
            subjectChain: $this->subjectChain,
            appliedLayers: $this->appliedLayers,
            fieldSources: $fieldSources,
            fields: $this->explanations,
            effectivePolicy: $this,
        );
    }
}
