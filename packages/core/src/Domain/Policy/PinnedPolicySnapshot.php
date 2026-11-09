<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use InvalidArgumentException;
use JsonException;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\Uuid;
use stdClass;

final readonly class PinnedPolicySnapshot
{
    /** @var array<string, int> */
    public array $layerRevisions;

    /** @var list<string> */
    public array $deniedRoutes;

    /** @var ?list<string> */
    public ?array $allowedDestinations;

    /** @var list<BudgetConstraint> */
    public array $budgets;

    /**
     * @param  array<string, int>  $layerRevisions
     * @param  list<string>  $deniedRoutes
     * @param  ?list<string>  $allowedDestinations
     * @param  list<BudgetConstraint>  $budgets
     */
    public function __construct(
        public TenantKey $tenantKey,
        public Uuid $targetId,
        public string $destinationPolicyKey,
        public ?Uuid $routeProfileId,
        public ?string $routeProfileKey,
        public ?int $routeProfileRevision,
        public int $effectiveRevision,
        public ContentHash $policyHash,
        array $layerRevisions,
        public string $strategyKey,
        public string $outcomePolicyKey,
        public RequestTimeoutPolicy $timeouts,
        public bool $retryDelaysDisabled = false,
        public bool $allowDirectRoute = false,
        array $deniedRoutes = [],
        ?array $allowedDestinations = null,
        array $budgets = [],
        public int $schemaVersion = 1,
    ) {
        if ($schemaVersion !== 1 || $effectiveRevision < 1) {
            throw new InvalidSettingPatch;
        }

        if (preg_match('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D', $destinationPolicyKey) !== 1
            || preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $strategyKey) !== 1
            || preg_match('/^[a-z0-9][a-z0-9_.:-]{0,63}$/D', $outcomePolicyKey) !== 1) {
            throw new InvalidSettingPatch;
        }

        if (($routeProfileKey !== null && preg_match('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D', $routeProfileKey) !== 1)
            || ($routeProfileRevision !== null && $routeProfileRevision < 1)) {
            throw new InvalidSettingPatch;
        }

        $normalizedRevisions = [];
        foreach ($layerRevisions as $layerKey => $revision) {
            if ($layerKey === '' || $revision < 1) {
                throw new InvalidSettingPatch;
            }
            $normalizedRevisions[$layerKey] = $revision;
        }
        ksort($normalizedRevisions, SORT_STRING);
        $this->layerRevisions = $normalizedRevisions;

        $normalizedDenied = [];
        foreach ($deniedRoutes as $routeKey) {
            if (preg_match('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D', $routeKey) !== 1) {
                throw new InvalidSettingPatch;
            }
            if (! in_array($routeKey, $normalizedDenied, true)) {
                $normalizedDenied[] = $routeKey;
            }
        }
        $this->deniedRoutes = $normalizedDenied;

        if ($allowedDestinations !== null) {
            $normalizedAllowed = [];
            foreach ($allowedDestinations as $destKey) {
                if (preg_match('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D', $destKey) !== 1) {
                    throw new InvalidSettingPatch;
                }
                if (! in_array($destKey, $normalizedAllowed, true)) {
                    $normalizedAllowed[] = $destKey;
                }
            }
            $this->allowedDestinations = $normalizedAllowed;
        } else {
            $this->allowedDestinations = null;
        }

        $mergedBudgets = [];
        foreach ($budgets as $budget) {
            $mergedBudgets[$budget->scopeKey] = isset($mergedBudgets[$budget->scopeKey])
                ? $mergedBudgets[$budget->scopeKey]->intersect($budget)
                : $budget;
        }
        ksort($mergedBudgets, SORT_STRING);
        $this->budgets = array_values($mergedBudgets);
    }

    public function toPolicyLayer(): PolicyLayer
    {
        $changes = [
            'timeout.connect_ms' => SettingChange::set($this->timeouts->connectTimeoutMs),
            'timeout.read_ms' => SettingChange::set($this->timeouts->readTimeoutMs),
            'timeout.total_ms' => SettingChange::set($this->timeouts->totalTimeoutMs),
            'retry.max_attempts' => SettingChange::set($this->timeouts->maxAttempts),
            'retry.delays_ms' => $this->retryDelaysDisabled
                ? SettingChange::disable()
                : SettingChange::set($this->timeouts->retryDelaysMs),
            'http.max_redirects' => SettingChange::set($this->timeouts->maxRedirects),
            'route.allow_direct' => SettingChange::set($this->allowDirectRoute),
            'routes.denied' => SettingChange::set($this->deniedRoutes),
            'destinations.allowed' => SettingChange::set($this->allowedDestinations),
            'route.strategy' => SettingChange::set($this->strategyKey),
        ];

        return new PolicyLayer(
            kind: PolicyLayerKind::PinnedExecutionSnapshot,
            scopeKey: 'pinned_snapshot',
            patch: new SettingPatch($this->schemaVersion, $changes),
            revision: $this->effectiveRevision,
            profileKey: $this->routeProfileKey,
            profileId: $this->routeProfileId,
            profileRevision: $this->routeProfileRevision,
            allowedProfileKeys: $this->routeProfileKey !== null ? [$this->routeProfileKey] : null,
            outcomePolicyKey: $this->outcomePolicyKey,
            budgets: $this->budgets,
        );
    }

    public function encode(): string
    {
        $budgets = array_map(
            static fn (BudgetConstraint $b): array => [
                'scope_key' => $b->scopeKey,
                'emission_interval_us' => $b->emissionIntervalUs,
                'burst' => $b->burst,
                'max_inflight' => $b->maxInflight,
                'mandatory' => $b->mandatory,
            ],
            $this->budgets,
        );

        $payload = [
            'schema_version' => $this->schemaVersion,
            'tenant_key' => $this->tenantKey->value,
            'target_id' => $this->targetId->value,
            'destination_policy_key' => $this->destinationPolicyKey,
            'route_profile_id' => $this->routeProfileId?->value,
            'route_profile_key' => $this->routeProfileKey,
            'route_profile_revision' => $this->routeProfileRevision,
            'effective_revision' => $this->effectiveRevision,
            'policy_hash' => $this->policyHash->hex,
            'layer_revisions' => (object) $this->layerRevisions,
            'strategy_key' => $this->strategyKey,
            'outcome_policy_key' => $this->outcomePolicyKey,
            'timeouts' => [
                'connect_ms' => $this->timeouts->connectTimeoutMs,
                'read_ms' => $this->timeouts->readTimeoutMs,
                'total_ms' => $this->timeouts->totalTimeoutMs,
                'cleanup_margin_ms' => $this->timeouts->cleanupMarginMs,
                'max_attempts' => $this->timeouts->maxAttempts,
                'max_distinct_routes' => $this->timeouts->maxDistinctRoutes,
                'max_redirects' => $this->timeouts->maxRedirects,
                'retry_delays_ms' => $this->timeouts->retryDelaysMs,
                'retry_delays_disabled' => $this->retryDelaysDisabled,
            ],
            'allow_direct_route' => $this->allowDirectRoute,
            'denied_routes' => $this->deniedRoutes,
            'allowed_destinations' => $this->allowedDestinations,
            'budgets' => $budgets,
        ];

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    public static function decode(string $json): self
    {
        if ($json === '' || strlen($json) > 65536) {
            throw new InvalidSettingPatch;
        }

        try {
            $data = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidSettingPatch;
        }

        $expectedKeys = [
            'allow_direct_route',
            'allowed_destinations',
            'budgets',
            'denied_routes',
            'destination_policy_key',
            'effective_revision',
            'layer_revisions',
            'outcome_policy_key',
            'policy_hash',
            'route_profile_id',
            'route_profile_key',
            'route_profile_revision',
            'schema_version',
            'strategy_key',
            'target_id',
            'tenant_key',
            'timeouts',
        ];

        if (! $data instanceof stdClass || ! self::exactKeys($data, $expectedKeys)
            || $data->schema_version !== 1
            || ! is_string($data->tenant_key)
            || ! is_string($data->target_id)
            || ! is_string($data->destination_policy_key)
            || ($data->route_profile_id !== null && ! is_string($data->route_profile_id))
            || ($data->route_profile_key !== null && ! is_string($data->route_profile_key))
            || ($data->route_profile_revision !== null && ! is_int($data->route_profile_revision))
            || ! is_int($data->effective_revision)
            || ! is_string($data->policy_hash)
            || ! $data->layer_revisions instanceof stdClass
            || ! is_string($data->strategy_key)
            || ! is_string($data->outcome_policy_key)
            || ! $data->timeouts instanceof stdClass
            || ! is_bool($data->allow_direct_route)
            || ! is_array($data->denied_routes)
            || ($data->allowed_destinations !== null && ! is_array($data->allowed_destinations))
            || ! is_array($data->budgets)) {
            throw new InvalidSettingPatch;
        }

        $timeoutKeys = [
            'cleanup_margin_ms',
            'connect_ms',
            'max_attempts',
            'max_distinct_routes',
            'max_redirects',
            'read_ms',
            'retry_delays_disabled',
            'retry_delays_ms',
            'total_ms',
        ];

        $t = $data->timeouts;
        if (! self::exactKeys($t, $timeoutKeys)
            || ! is_int($t->connect_ms)
            || ! is_int($t->read_ms)
            || ! is_int($t->total_ms)
            || ! is_int($t->cleanup_margin_ms)
            || ! is_int($t->max_attempts)
            || ! is_int($t->max_distinct_routes)
            || ! is_int($t->max_redirects)
            || ! is_array($t->retry_delays_ms)
            || ! is_bool($t->retry_delays_disabled)) {
            throw new InvalidSettingPatch;
        }

        try {
            $delays = [];
            foreach ($t->retry_delays_ms as $delay) {
                if (! is_int($delay)) {
                    throw new InvalidSettingPatch;
                }
                $delays[] = $delay;
            }

            $timeouts = new RequestTimeoutPolicy(
                connectTimeoutMs: $t->connect_ms,
                readTimeoutMs: $t->read_ms,
                totalTimeoutMs: $t->total_ms,
                cleanupMarginMs: $t->cleanup_margin_ms,
                maxAttempts: $t->max_attempts,
                maxDistinctRoutes: $t->max_distinct_routes,
                maxRedirects: $t->max_redirects,
                retryDelaysMs: $delays,
            );

            $layerRevisions = [];
            foreach (get_object_vars($data->layer_revisions) as $k => $v) {
                if (! is_int($v)) {
                    throw new InvalidSettingPatch;
                }
                $layerRevisions[$k] = $v;
            }

            $deniedRoutes = [];
            foreach ($data->denied_routes as $routeKey) {
                if (! is_string($routeKey)) {
                    throw new InvalidSettingPatch;
                }
                $deniedRoutes[] = $routeKey;
            }

            $allowedDestinations = null;
            if (is_array($data->allowed_destinations)) {
                $allowedDestinations = [];
                foreach ($data->allowed_destinations as $destKey) {
                    if (! is_string($destKey)) {
                        throw new InvalidSettingPatch;
                    }
                    $allowedDestinations[] = $destKey;
                }
            }

            $budgets = [];
            foreach ($data->budgets as $rawBudget) {
                if (! $rawBudget instanceof stdClass
                    || ! self::exactKeys($rawBudget, ['burst', 'emission_interval_us', 'mandatory', 'max_inflight', 'scope_key'])
                    || ! is_string($rawBudget->scope_key)
                    || ! is_int($rawBudget->emission_interval_us)
                    || ! is_int($rawBudget->burst)
                    || ! is_int($rawBudget->max_inflight)
                    || ! is_bool($rawBudget->mandatory)) {
                    throw new InvalidSettingPatch;
                }
                $budgets[] = new BudgetConstraint(
                    scopeKey: $rawBudget->scope_key,
                    emissionIntervalUs: $rawBudget->emission_interval_us,
                    burst: $rawBudget->burst,
                    maxInflight: $rawBudget->max_inflight,
                    mandatory: $rawBudget->mandatory,
                );
            }

            return new self(
                tenantKey: new TenantKey($data->tenant_key),
                targetId: new Uuid($data->target_id),
                destinationPolicyKey: $data->destination_policy_key,
                routeProfileId: $data->route_profile_id === null ? null : new Uuid($data->route_profile_id),
                routeProfileKey: $data->route_profile_key,
                routeProfileRevision: $data->route_profile_revision,
                effectiveRevision: $data->effective_revision,
                policyHash: new ContentHash($data->policy_hash),
                layerRevisions: $layerRevisions,
                strategyKey: $data->strategy_key,
                outcomePolicyKey: $data->outcome_policy_key,
                timeouts: $timeouts,
                retryDelaysDisabled: $t->retry_delays_disabled,
                allowDirectRoute: $data->allow_direct_route,
                deniedRoutes: $deniedRoutes,
                allowedDestinations: $allowedDestinations,
                budgets: $budgets,
                schemaVersion: $data->schema_version,
            );
        } catch (InvalidArgumentException) {
            throw new InvalidSettingPatch;
        }
    }

    /**
     * @param  list<string>  $expectedSorted
     */
    private static function exactKeys(stdClass $object, array $expectedSorted): bool
    {
        $actual = array_keys(get_object_vars($object));
        sort($actual, SORT_STRING);

        return $actual === $expectedSorted;
    }
}
