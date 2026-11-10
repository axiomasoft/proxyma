<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use Proxyma\Application\Admission\TrafficSharePolicy;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Routing\StrategyRegistry;
use Proxyma\Contracts\CandidateSnapshots;
use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\EffectivePolicy;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Domain\Routing\Candidate;
use Proxyma\Domain\Routing\CandidateSnapshot;
use Proxyma\Infrastructure\Audit\CatalogAudit;
use Proxyma\Infrastructure\Security\SharedRouteStorage;

final readonly class CandidateSnapshotQuery implements CandidateSnapshots
{
    public function __construct(private Connection $db, private CatalogAccess $access, private StrategyRegistry $strategies,
        private TrafficSharePolicy $traffic = new TrafficSharePolicy) {}

    public function load(TenantKey $tenant, Uuid $profileId, Uuid $targetId, EffectivePolicy $policy): CandidateSnapshot
    {
        return $this->snapshot($tenant, $profileId, $targetId, $policy, true);
    }

    public function readOnly(TenantKey $tenant, Uuid $profileId, Uuid $targetId, EffectivePolicy $policy): CandidateSnapshot
    {
        return $this->snapshot($tenant, $profileId, $targetId, $policy, false);
    }

    private function snapshot(TenantKey $tenant, Uuid $profileId, Uuid $targetId, EffectivePolicy $policy, bool $recordAudit): CandidateSnapshot
    {
        if ($this->db->transactionLevel() !== 0) {
            throw new InvalidArgumentException('Resolve candidates before opening a SQL transaction.');
        }
        $context = $this->access->authorize($tenant, 'catalog.profile.select', $profileId);
        $targetContext = $this->access->authorize($tenant, 'catalog.target.read', $targetId);
        if (! $tenant->equals($policy->tenantKey) || ! $targetId->equals($policy->targetId)) {
            throw new AuthorizationDenied;
        }
        $t = PackageSchema::tables();
        $now = new DateTimeImmutable($this->db->selectOne('SELECT clock_timestamp() AS now')->now);
        $profile = $this->db->table($t['route_profiles'].' as p')
            ->leftJoin($t['profile_health'].' as h', fn ($j) => $j->on('h.tenant_key', '=', 'p.tenant_key')->on('h.profile_id', '=', 'p.id'))
            ->where('p.tenant_key', $tenant->value)->where('p.id', $profileId->value)
            ->select(['p.*', 'h.state as health_state', 'h.health_epoch as health_epoch', 'h.state_version as health_version'])->first();
        $target = $this->db->table($t['targets'].' as x')
            ->leftJoin($t['target_health'].' as h', fn ($j) => $j->on('h.tenant_key', '=', 'x.tenant_key')->on('h.target_id', '=', 'x.id'))
            ->where('x.tenant_key', $tenant->value)->where('x.id', $targetId->value)
            ->select(['x.*', 'h.state as health_state', 'h.availability as availability', 'h.health_epoch as health_epoch', 'h.state_version as health_version'])->first();
        if ($recordAudit) {
            $audit = new CatalogAudit($this->db);
            $audit->read('route_profiles', $profileId, 'catalog.profile.select', $context, (int) ($profile->revision ?? 0));
            $audit->read('targets', $targetId, 'catalog.target.read', $targetContext, (int) ($target->revision ?? 0));
        }
        $holds = [];
        if ($profile === null || $profile->retired_at !== null) {
            $holds[] = 'profile_missing_or_retired';
        }
        if ($target === null || $target->retired_at !== null) {
            $holds[] = 'target_missing_or_retired';
        }
        if ($profile !== null && ($profile->health_state !== 'closed' || $profile->health_epoch === null)) {
            $holds[] = 'profile_health_unavailable';
        }
        if ($profile !== null) {
            try {
                $this->strategies->get($profile->strategy_key);
            } catch (InvalidArgumentException) {
                $holds[] = 'unknown_profile_strategy';
            }
        }
        if ($target !== null && ($target->health_state !== 'closed' || ! in_array($target->availability, ['available', 'suspect'], true))) {
            $holds[] = 'target_health_unavailable';
        }
        if ($profile === null || $target === null) {
            return new CandidateSnapshot($tenant, null, $target === null ? null : CatalogRowMapper::target($target), $policy->effectiveRevision, $now, [], $holds);
        }
        $targetValue = CatalogRowMapper::target($target);
        if (($policy->routeProfileId !== null && ! $policy->routeProfileId->equals($profileId))
            || ($policy->routeProfileKey !== null && $policy->routeProfileKey !== $profile->profile_key)
            || ($policy->routeProfileRevision !== null && $policy->routeProfileRevision !== (int) $profile->revision)
            || ! $policy->isProfileAllowed($profile->profile_key)
            || ! $policy->isDestinationAllowed($targetValue->destinationPolicyKey)) {
            $holds[] = 'policy_profile_or_destination_mismatch';
        }

        // Сначала membership и его точный owner/grant. Ни health, ни budgets пока не читаем.
        $rows = $this->db->table($t['route_profile_members'].' as m')
            ->leftJoin($t['routes'].' as r', fn ($j) => $j->on('r.id', '=', 'm.route_id')->on('r.tenant_key', '=', 'm.route_owner_tenant_key'))
            ->leftJoin($t['endpoints'].' as e', fn ($j) => $j->on('e.id', '=', 'r.endpoint_id')->on('e.tenant_key', '=', 'r.tenant_key'))
            ->leftJoin($t['shared_route_grants'].' as g', fn ($j) => $j->on('g.id', '=', 'm.shared_grant_id')->on('g.tenant_key', '=', 'm.route_owner_tenant_key')
                ->on('g.consumer_tenant_key', '=', 'm.tenant_key')->on('g.route_id', '=', 'm.route_id'))
            ->where('m.tenant_key', $tenant->value)->where('m.profile_id', $profileId->value)
            ->orderBy('m.position')->select(['r.*', 'm.route_id as member_id', 'm.route_owner_tenant_key as owner_tenant', 'm.shared_grant_id',
                'e.protocol as endpoint_protocol', 'e.retired_at as endpoint_retired_at', 'e.revision as endpoint_revision', 'e.credential_version',
                'g.id as grant_id', 'g.tenant_key as grant_owner', 'g.consumer_tenant_key as grant_consumer', 'g.route_id as grant_route',
                'g.revision as grant_revision', 'g.retired_at as grant_retired_at'])->get();
        $profile->member_route_ids = $rows->pluck('member_id')->all();
        $profileValue = CatalogRowMapper::profile($profile);
        if ($profileValue->isEmpty()) {
            $holds[] = 'empty_profile';
        }
        $checked = [];
        $authorizedIds = [];
        foreach ($rows as $row) {
            $id = new Uuid($row->member_id);
            $reason = [];
            try {
                $this->access->authorize($tenant, 'catalog.route.select', $id);
            } catch (AuthorizationDenied) {
                $reason[] = 'route_permission_denied';
            }
            $grant = null;
            if ($row->owner_tenant !== $tenant->value) {
                if ($row->grant_id === null || $row->grant_retired_at !== null) {
                    $reason[] = 'shared_grant_missing_or_retired';
                } else {
                    $grant = SharedRouteStorage::map((object) ['id' => $row->grant_id, 'tenant_key' => $row->grant_owner,
                        'consumer_tenant_key' => $row->grant_consumer, 'route_id' => $row->grant_route,
                        'revision' => $row->grant_revision, 'retired_at' => $row->grant_retired_at]);
                }
            } elseif ($row->shared_grant_id !== null) {
                $reason[] = 'shared_grant_mismatch';
            }
            if ($row->id === null || ! in_array($row->kind, ['direct', 'proxy'], true)
                || ($row->kind === 'proxy' && ($row->endpoint_revision === null || $row->endpoint_retired_at !== null
                    || ! is_string($row->endpoint_protocol) || ProxyProtocol::tryFrom($row->endpoint_protocol) === null))) {
                $reason[] = 'route_or_endpoint_configuration_invalid';
            }
            $route = null;
            if (! in_array('route_or_endpoint_configuration_invalid', $reason, true) && ! in_array('shared_grant_missing_or_retired', $reason, true)) {
                $route = CatalogRowMapper::route($row, $grant);
                if (! $route->enabled || $route->isRetired()) {
                    $reason[] = 'route_disabled_or_retired';
                }
                if ($route->kind === RouteKind::Direct && ! $policy->allowDirectRoute) {
                    $reason[] = 'direct_not_allowed';
                }
                if ($policy->isRouteDenied($route)) {
                    $reason[] = 'route_denied_by_policy';
                }
            }
            if ($reason === []) {
                $authorizedIds[] = $id->value;
            }
            if (in_array('route_permission_denied', $reason, true)) {
                $route = null;
            }
            $checked[$id->value] = ['id' => $id, 'route' => $route, 'reason' => $reason];
        }
        $health = [];
        $history = [];
        $budgets = [];
        if ($authorizedIds !== []) {
            $healthRows = $this->db->table($t['routes'].' as r')
                ->leftJoin($t['route_health'].' as h', fn ($j) => $j->on('h.tenant_key', '=', 'r.tenant_key')->on('h.route_id', '=', 'r.id'))
                ->leftJoin($t['route_target_health'].' as rh', fn ($j) => $j->on('rh.tenant_key', '=', 'r.tenant_key')->on('rh.route_id', '=', 'r.id')->where('rh.target_id', '=', $targetId->value))
                ->whereIn('r.id', $authorizedIds)->select(['r.id', 'h.state as route_state', 'h.health_epoch as route_epoch', 'h.state_version as route_version',
                    'rh.state as pair_state', 'rh.health_epoch as pair_epoch', 'rh.state_version as pair_version', 'rh.open_until as pair_open_until'])->get();
            foreach ($healthRows as $row) {
                $health[$row->id] = $row;
            }
            $historyRows = $this->db->table($t['permits'])->where('tenant_key', $tenant->value)->whereIn('route_id', $authorizedIds)
                ->whereNotNull('authorized_at')->groupBy('route_id')->selectRaw('route_id, max(authorized_at) as last_authorized')->get();
            foreach ($historyRows as $row) {
                $history[$row->route_id] = new DateTimeImmutable($row->last_authorized);
            }
            $keys = array_keys($policy->budgets);
            $keys[] = $targetValue->origin->budgetScopeKey();
            $keys[] = TrafficSharePolicy::scope($targetValue->origin, $policy->trafficKey);
            foreach ($authorizedIds as $id) {
                $route = $checked[$id]['route'];
                $keys[] = $targetValue->origin->routeScopeKey($id);
                $keys[] = $targetValue->origin->egressScopeKey($route->egress);
            }
            $quota = $t['quota_windows'];
            $budgetRows = $this->db->table($t['budgets'].' as b')
                ->whereIn('b.tenant_key', array_values(array_unique([$tenant->value, TenantKey::SYSTEM])))
                ->whereIn('b.scope_key', array_values(array_unique($keys)))
                ->select(['b.*'])->selectRaw('extract(epoch from b.tat) as tat_epoch, extract(epoch from b.send_tat) as send_tat_epoch')
                ->selectRaw("(SELECT q.reserved+q.spent FROM {$quota} q WHERE q.budget_id=b.id AND q.policy_key=b.quota_policy_key AND q.window_kind='hour'
                    AND q.starts_at<=clock_timestamp() AND q.ends_at>clock_timestamp()) AS hour_used,
                    (SELECT q.reserved+q.spent FROM {$quota} q WHERE q.budget_id=b.id AND q.policy_key=b.quota_policy_key AND q.window_kind='day'
                    AND q.starts_at<=clock_timestamp() AND q.ends_at>clock_timestamp()) AS day_used")
                ->selectRaw("EXISTS (SELECT 1 FROM {$quota} q WHERE q.tenant_key = b.tenant_key AND q.budget_id = b.id AND q.policy_key=b.quota_policy_key
                    AND ((q.window_kind='hour' AND b.hour_limit IS NOT NULL) OR (q.window_kind='day' AND b.day_limit IS NOT NULL))
                    AND q.starts_at <= clock_timestamp() AND q.ends_at > clock_timestamp() AND q.spent >= q.quota_limit-q.reserved) AS quota_exhausted")
                ->selectRaw("((b.hour_limit IS NOT NULL AND NOT EXISTS (SELECT 1 FROM {$quota} q WHERE q.budget_id=b.id AND q.window_kind='hour'
                    AND q.policy_key=b.quota_policy_key AND q.starts_at<=clock_timestamp() AND q.ends_at>clock_timestamp())) OR
                    (b.day_limit IS NOT NULL AND NOT EXISTS (SELECT 1 FROM {$quota} q WHERE q.budget_id=b.id AND q.window_kind='day'
                    AND q.policy_key=b.quota_policy_key AND q.starts_at<=clock_timestamp() AND q.ends_at>clock_timestamp()))) AS quota_missing")
                ->get();
            foreach ($budgetRows as $row) {
                $budgets[$row->scope_key][$row->tenant_key] = $row;
            }
        }
        $candidates = [];
        foreach ($checked as $id => $item) {
            $route = $item['route'];
            $reasons = $item['reason'];
            $epochs = [];
            $versions = [];
            $scopes = [];
            $budgetVersions = [];
            $ready = true;
            if ($reasons === [] && $route !== null) {
                $h = $health[$id] ?? null;
                if ($h === null || $h->route_state !== 'closed' || $h->pair_state !== 'closed'
                    || ($h->pair_open_until !== null && new DateTimeImmutable($h->pair_open_until) > $now)) {
                    $reasons[] = 'route_health_unavailable';
                }
                foreach (['route' => [$h?->route_epoch, $h?->route_version], 'route_target' => [$h?->pair_epoch, $h?->pair_version],
                    'profile' => [$profile->health_epoch, $profile->health_version], 'target' => [$target->health_epoch, $target->health_version]] as $key => [$epoch, $version]) {
                    if ($epoch !== null && $version !== null) {
                        $epochs[$key] = (int) $epoch;
                        $versions[$key] = (int) $version;
                    }
                }
                $special = [$targetValue->origin->budgetScopeKey(), $targetValue->origin->routeScopeKey($id), $targetValue->origin->egressScopeKey($route->egress)];
                if (array_key_exists($policy->trafficKey, $this->traffic->classes) || isset($budgets[TrafficSharePolicy::scope($targetValue->origin, $policy->trafficKey)])) {
                    $special[] = TrafficSharePolicy::scope($targetValue->origin, $policy->trafficKey);
                    if (! isset($budgets[TrafficSharePolicy::scope($targetValue->origin, $policy->trafficKey)])) {
                        $reasons[] = 'required_budget_missing';
                    }
                }
                $scopes = $special;
                foreach ($policy->budgets as $key => $constraint) {
                    if (str_starts_with($key, 'route-origin:') || str_starts_with($key, 'egress-origin:') || str_starts_with($key, 'origin:')) {
                        if (! in_array($key, $special, true)) {
                            continue;
                        }
                    }
                    $scopes[] = $key;
                    if (! isset($budgets[$key])) {
                        $reasons[] = 'required_budget_missing';
                    }
                }
                $scopes = array_values(array_unique($scopes));
                sort($scopes, SORT_STRING);
                foreach ($scopes as $key) {
                    foreach ($budgets[$key] ?? [] as $owner => $budget) {
                        $budgetVersions[$owner.':'.$key] = (int) $budget->revision;
                        $constraint = $policy->budgets[$key] ?? null;
                        foreach (['hour', 'day'] as $period) {
                            $limit = $budget->{$period.'_limit'};
                            $narrow = $period === 'hour' ? $constraint?->hourLimit : $constraint?->dayLimit;
                            if ($narrow !== null) {
                                $limit = $limit === null ? $narrow : min((int) $limit, $narrow);
                            }
                            if ($period === 'hour' && $budget->traffic_class !== null) {
                                $parent = $budgets[$targetValue->origin->budgetScopeKey()][$owner] ?? null;
                                if ($parent !== null) {
                                    $derived = TrafficSharePolicy::hourlyLimit((int) $parent->emission_interval_us,
                                        min((int) $budget->traffic_percent, $this->traffic->classes[$policy->trafficKey] ?? 0));
                                    $limit = $limit === null ? $derived : min((int) $limit, $derived);
                                }
                            }
                            if ($limit !== null && $budget->{$period.'_used'} === null) {
                                $reasons[] = 'quota_materialization_missing';
                            } elseif ($limit !== null && (int) $budget->{$period.'_used'} >= $limit) {
                                $reasons[] = 'quota_exhausted';
                            }
                        }
                        $maxInflight = min((int) $budget->max_inflight, $constraint === null ? PHP_INT_MAX : $constraint->maxInflight);
                        $interval = max((int) $budget->emission_interval_us, $constraint === null ? 0 : $constraint->emissionIntervalUs);
                        $burst = min((int) $budget->burst, $constraint === null ? PHP_INT_MAX : $constraint->burst);
                        $threshold = (float) $now->format('U.u');
                        if ($budget->cooldown_until !== '-infinity' && new DateTimeImmutable($budget->cooldown_until) > $now) {
                            $ready = false;
                        }
                        foreach ([$budget->tat_epoch, $budget->send_tat_epoch] as $tat) {
                            if (is_numeric($tat) && (float) $tat - ($interval * ($burst - 1) / 1_000_000) > $threshold) {
                                $ready = false;
                            }
                        }
                        if ((int) $budget->inflight >= $maxInflight) {
                            $ready = false;
                        }
                        if ($budget->quota_exhausted) {
                            $reasons[] = 'quota_exhausted';
                            $ready = false;
                        }
                        if ($budget->quota_missing) {
                            $reasons[] = 'quota_materialization_missing';
                        }
                    }
                }
            }
            $candidates[] = new Candidate($item['id'], $route, array_values(array_unique([...$reasons, ...$holds])), $epochs, $versions,
                $budgetVersions, $scopes, $ready, $history[$id] ?? null);
        }

        return new CandidateSnapshot($tenant, $profileValue, $targetValue, $policy->effectiveRevision, $now, $candidates, $holds);
    }
}
