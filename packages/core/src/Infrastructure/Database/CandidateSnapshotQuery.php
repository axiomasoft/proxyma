<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use InvalidArgumentException;
use Packman\Attributes\Api;
use Proxyma\Application\Admission\TrafficSharePolicy;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Routing\StrategyRegistry;
use Proxyma\Contracts\Catalog\CandidateSnapshots;
use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\EffectivePolicy;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Domain\Routing\Candidate;
use Proxyma\Domain\Routing\CandidateSnapshot;
use Proxyma\Infrastructure\Audit\CatalogAudit;
use Proxyma\Infrastructure\Security\SharedRouteStorage;
use Proxyma\Internal\Database\RowValues;
use stdClass;

#[Api(since: '1.0')]
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
        $now = DatabaseClock::now($this->db);
        $profile = $this->db->table($t['route_profiles'].' as p')
            ->leftJoin($t['profile_health'].' as h', fn (JoinClause $j): JoinClause => $j->on('h.tenant_key', '=', 'p.tenant_key')->on('h.profile_id', '=', 'p.id'))
            ->where('p.tenant_key', $tenant->value)->where('p.id', $profileId->value)
            ->select(['p.*', 'h.state as health_state', 'h.health_epoch as health_epoch', 'h.state_version as health_version'])->first();
        $target = $this->db->table($t['targets'].' as x')
            ->leftJoin($t['target_health'].' as h', fn (JoinClause $j): JoinClause => $j->on('h.tenant_key', '=', 'x.tenant_key')->on('h.target_id', '=', 'x.id'))
            ->where('x.tenant_key', $tenant->value)->where('x.id', $targetId->value)
            ->select(['x.*', 'h.state as health_state', 'h.availability as availability', 'h.health_epoch as health_epoch', 'h.state_version as health_version'])->first();
        if ($recordAudit) {
            $audit = new CatalogAudit($this->db);
            $audit->read('route_profiles', $profileId, 'catalog.profile.select', $context, $profile === null ? 0 : RowValues::colInt($profile, 'revision'));
            $audit->read('targets', $targetId, 'catalog.target.read', $targetContext, $target === null ? 0 : RowValues::colInt($target, 'revision'));
        }
        $holds = [];
        if ($profile === null || RowValues::col($profile, 'retired_at') !== null) {
            $holds[] = 'profile_missing_or_retired';
        }
        if ($target === null || RowValues::col($target, 'retired_at') !== null) {
            $holds[] = 'target_missing_or_retired';
        }
        if ($profile !== null && (RowValues::colNullableStr($profile, 'health_state') !== 'closed' || RowValues::col($profile, 'health_epoch') === null)) {
            $holds[] = 'profile_health_unavailable';
        }
        if ($profile !== null) {
            try {
                $this->strategies->get(RowValues::colStr($profile, 'strategy_key'));
            } catch (InvalidArgumentException) {
                $holds[] = 'unknown_profile_strategy';
            }
        }
        if ($target !== null && (RowValues::colNullableStr($target, 'health_state') !== 'closed'
            || ! in_array(RowValues::colNullableStr($target, 'availability'), ['available', 'suspect'], true))) {
            $holds[] = 'target_health_unavailable';
        }
        if ($profile === null || $target === null) {
            return new CandidateSnapshot($tenant, null, $target === null ? null : CatalogRowMapper::target($target), $policy->effectiveRevision, $now, [], $holds);
        }
        $targetValue = CatalogRowMapper::target($target);
        $profileKey = RowValues::colStr($profile, 'profile_key');
        if (($policy->routeProfileId !== null && ! $policy->routeProfileId->equals($profileId))
            || ($policy->routeProfileKey !== null && $policy->routeProfileKey !== $profileKey)
            || ($policy->routeProfileRevision !== null && $policy->routeProfileRevision !== RowValues::colInt($profile, 'revision'))
            || ! $policy->isProfileAllowed($profileKey)
            || ! $policy->isDestinationAllowed($targetValue->destinationPolicyKey)) {
            $holds[] = 'policy_profile_or_destination_mismatch';
        }

        // Сначала membership и его точный owner/grant. Ни health, ни budgets пока не читаем.
        $rows = $this->db->table($t['route_profile_members'].' as m')
            ->leftJoin($t['routes'].' as r', fn (JoinClause $j): JoinClause => $j->on('r.id', '=', 'm.route_id')->on('r.tenant_key', '=', 'm.route_owner_tenant_key'))
            ->leftJoin($t['endpoints'].' as e', fn (JoinClause $j): JoinClause => $j->on('e.id', '=', 'r.endpoint_id')->on('e.tenant_key', '=', 'r.tenant_key'))
            ->leftJoin($t['shared_route_grants'].' as g', fn (JoinClause $j): JoinClause => $j->on('g.id', '=', 'm.shared_grant_id')->on('g.tenant_key', '=', 'm.route_owner_tenant_key')
                ->on('g.consumer_tenant_key', '=', 'm.tenant_key')->on('g.route_id', '=', 'm.route_id'))
            ->where('m.tenant_key', $tenant->value)->where('m.profile_id', $profileId->value)
            ->orderBy('m.position')->select(['r.*', 'm.route_id as member_id', 'm.route_owner_tenant_key as owner_tenant', 'm.shared_grant_id',
                'e.protocol as endpoint_protocol', 'e.retired_at as endpoint_retired_at', 'e.revision as endpoint_revision', 'e.credential_version',
                'g.id as grant_id', 'g.tenant_key as grant_owner', 'g.consumer_tenant_key as grant_consumer', 'g.route_id as grant_route',
                'g.revision as grant_revision', 'g.retired_at as grant_retired_at'])->get();
        $profileValue = CatalogRowMapper::profile($profile, RowValues::strList($rows->pluck('member_id')->all()));
        if ($profileValue->isEmpty()) {
            $holds[] = 'empty_profile';
        }
        $checked = [];
        $authorizedIds = [];
        foreach ($rows as $row) {
            $id = new Uuid(RowValues::colStr($row, 'member_id'));
            $reason = [];
            try {
                $this->access->authorize($tenant, 'catalog.route.select', $id);
            } catch (AuthorizationDenied) {
                $reason[] = 'route_permission_denied';
            }
            $grant = null;
            if (RowValues::colStr($row, 'owner_tenant') !== $tenant->value) {
                $grantId = RowValues::colNullableStr($row, 'grant_id');
                if ($grantId === null || RowValues::col($row, 'grant_retired_at') !== null) {
                    $reason[] = 'shared_grant_missing_or_retired';
                } else {
                    $grant = SharedRouteStorage::map((object) ['id' => $grantId, 'tenant_key' => RowValues::col($row, 'grant_owner'),
                        'consumer_tenant_key' => RowValues::col($row, 'grant_consumer'), 'route_id' => RowValues::col($row, 'grant_route'),
                        'revision' => RowValues::col($row, 'grant_revision'), 'retired_at' => RowValues::col($row, 'grant_retired_at')]);
                }
            } elseif (RowValues::col($row, 'shared_grant_id') !== null) {
                $reason[] = 'shared_grant_mismatch';
            }
            $rowKind = RowValues::colNullableStr($row, 'kind');
            $endpointProtocol = RowValues::colNullableStr($row, 'endpoint_protocol');
            if (RowValues::col($row, 'id') === null || ! in_array($rowKind, ['direct', 'proxy'], true)
                || ($rowKind === 'proxy' && (RowValues::col($row, 'endpoint_revision') === null || RowValues::col($row, 'endpoint_retired_at') !== null
                    || $endpointProtocol === null || ProxyProtocol::tryFrom($endpointProtocol) === null))) {
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
        /** @var array<string, stdClass> $health */
        $health = [];
        /** @var array<string, DateTimeImmutable> $history */
        $history = [];
        /** @var array<string, array<string, stdClass>> $budgets */
        $budgets = [];
        if ($authorizedIds !== []) {
            $healthRows = $this->db->table($t['routes'].' as r')
                ->leftJoin($t['route_health'].' as h', fn (JoinClause $j): JoinClause => $j->on('h.tenant_key', '=', 'r.tenant_key')->on('h.route_id', '=', 'r.id'))
                ->leftJoin($t['route_target_health'].' as rh', fn (JoinClause $j): JoinClause => $j->on('rh.tenant_key', '=', 'r.tenant_key')->on('rh.route_id', '=', 'r.id')->where('rh.target_id', '=', $targetId->value))
                ->whereIn('r.id', $authorizedIds)->select(['r.id', 'h.state as route_state', 'h.health_epoch as route_epoch', 'h.state_version as route_version',
                    'rh.state as pair_state', 'rh.health_epoch as pair_epoch', 'rh.state_version as pair_version', 'rh.open_until as pair_open_until'])->get();
            foreach ($healthRows as $row) {
                $health[RowValues::colStr($row, 'id')] = $row;
            }
            $historyRows = $this->db->table($t['permits'])->where('tenant_key', $tenant->value)->whereIn('route_id', $authorizedIds)
                ->whereNotNull('authorized_at')->groupBy('route_id')->selectRaw('route_id, max(authorized_at) as last_authorized')->get();
            foreach ($historyRows as $row) {
                $history[RowValues::colStr($row, 'route_id')] = new DateTimeImmutable(RowValues::colStr($row, 'last_authorized'));
            }
            $keys = array_keys($policy->budgets);
            $keys[] = $targetValue->origin->budgetScopeKey();
            $keys[] = TrafficSharePolicy::scope($targetValue->origin, $policy->trafficKey);
            foreach ($authorizedIds as $id) {
                $route = $checked[$id]['route'];
                if ($route !== null) {
                    $keys[] = $targetValue->origin->routeScopeKey($id);
                    $keys[] = $targetValue->origin->egressScopeKey($route->egress);
                }
            }
            $quota = $t['quota_windows'];
            $budgetRows = $this->db->table($t['budgets'].' as b')
                ->whereIn('b.tenant_key', array_values(array_unique([$tenant->value, TenantKey::SYSTEM])))
                ->whereIn('b.scope_key', array_values(array_unique($keys)))
                ->select(['b.*'])->selectRaw('extract(epoch from b.tat) as tat_epoch, extract(epoch from b.send_tat) as send_tat_epoch')
                ->selectSub(static function (Builder $q) use ($quota): void {
                    $q->from($quota.' as q')->selectRaw('q.reserved + q.spent')
                        ->whereColumn('q.budget_id', 'b.id')->whereColumn('q.policy_key', 'b.quota_policy_key')
                        ->where('q.window_kind', 'hour')->whereRaw('q.starts_at <= clock_timestamp()')->whereRaw('q.ends_at > clock_timestamp()');
                }, 'hour_used')
                ->selectSub(static function (Builder $q) use ($quota): void {
                    $q->from($quota.' as q')->selectRaw('q.reserved + q.spent')
                        ->whereColumn('q.budget_id', 'b.id')->whereColumn('q.policy_key', 'b.quota_policy_key')
                        ->where('q.window_kind', 'day')->whereRaw('q.starts_at <= clock_timestamp()')->whereRaw('q.ends_at > clock_timestamp()');
                }, 'day_used')
                ->selectSub(static function (Builder $q) use ($quota): void {
                    $q->from($quota.' as q')->selectRaw('1')
                        ->whereColumn('q.tenant_key', 'b.tenant_key')->whereColumn('q.budget_id', 'b.id')
                        ->whereColumn('q.policy_key', 'b.quota_policy_key')
                        ->where(static function (Builder $w): void {
                            $w->where(static function (Builder $h): void {
                                $h->where('q.window_kind', 'hour')->whereNotNull('b.hour_limit');
                            })->orWhere(static function (Builder $d): void {
                                $d->where('q.window_kind', 'day')->whereNotNull('b.day_limit');
                            });
                        })
                        ->whereRaw('q.starts_at <= clock_timestamp()')->whereRaw('q.ends_at > clock_timestamp()')
                        ->whereRaw('q.spent >= q.quota_limit - q.reserved')->limit(1);
                }, 'quota_exhausted')
                ->get();
            foreach ($budgetRows as $row) {
                $budgets[RowValues::colStr($row, 'scope_key')][RowValues::colStr($row, 'tenant_key')] = $row;
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
                $pairOpenUntil = $h === null ? null : RowValues::colNullableStr($h, 'pair_open_until');
                if ($h === null || RowValues::colNullableStr($h, 'route_state') !== 'closed'
                    || RowValues::colNullableStr($h, 'pair_state') !== 'closed'
                    || ($pairOpenUntil !== null && new DateTimeImmutable($pairOpenUntil) > $now)) {
                    $reasons[] = 'route_health_unavailable';
                }
                $epochPairs = [
                    'route' => [$h === null ? null : RowValues::colNullableInt($h, 'route_epoch'), $h === null ? null : RowValues::colNullableInt($h, 'route_version')],
                    'route_target' => [$h === null ? null : RowValues::colNullableInt($h, 'pair_epoch'), $h === null ? null : RowValues::colNullableInt($h, 'pair_version')],
                    'profile' => [RowValues::colNullableInt($profile, 'health_epoch'), RowValues::colNullableInt($profile, 'health_version')],
                    'target' => [RowValues::colNullableInt($target, 'health_epoch'), RowValues::colNullableInt($target, 'health_version')],
                ];
                foreach ($epochPairs as $key => [$epoch, $version]) {
                    if ($epoch !== null && $version !== null) {
                        $epochs[$key] = $epoch;
                        $versions[$key] = $version;
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
                        $budgetVersions[$owner.':'.$key] = RowValues::colInt($budget, 'revision');
                        $constraint = $policy->budgets[$key] ?? null;
                        foreach (['hour', 'day'] as $period) {
                            $limit = RowValues::colNullableInt($budget, $period.'_limit');
                            $narrow = $period === 'hour' ? $constraint?->hourLimit : $constraint?->dayLimit;
                            if ($narrow !== null) {
                                $limit = $limit === null ? $narrow : min($limit, $narrow);
                            }
                            $trafficClass = RowValues::colNullableStr($budget, 'traffic_class');
                            if ($period === 'hour' && $trafficClass !== null) {
                                $parent = $budgets[$targetValue->origin->budgetScopeKey()][$owner] ?? null;
                                if ($parent !== null) {
                                    $derived = TrafficSharePolicy::hourlyLimit(
                                        RowValues::colInt($parent, 'emission_interval_us'),
                                        min(RowValues::colInt($budget, 'traffic_percent'), $this->traffic->classes[$policy->trafficKey] ?? 0),
                                    );
                                    $limit = $limit === null ? $derived : min($limit, $derived);
                                }
                            }
                            $used = RowValues::colNullableInt($budget, $period.'_used');
                            if ($limit !== null && $used === null) {
                                $reasons[] = 'quota_materialization_missing';
                            } elseif ($limit !== null && $used >= $limit) {
                                $reasons[] = 'quota_exhausted';
                            }
                        }
                        $maxInflight = min(RowValues::colInt($budget, 'max_inflight'), $constraint->maxInflight ?? PHP_INT_MAX);
                        $interval = max(RowValues::colInt($budget, 'emission_interval_us'), $constraint->emissionIntervalUs ?? 0);
                        $burst = min(RowValues::colInt($budget, 'burst'), $constraint->burst ?? PHP_INT_MAX);
                        $threshold = (float) $now->format('U.u');
                        $cooldownUntil = RowValues::colNullableStr($budget, 'cooldown_until');
                        if ($cooldownUntil !== null && $cooldownUntil !== '-infinity' && new DateTimeImmutable($cooldownUntil) > $now) {
                            $ready = false;
                        }
                        foreach ([RowValues::col($budget, 'tat_epoch'), RowValues::col($budget, 'send_tat_epoch')] as $tat) {
                            if (is_numeric($tat) && (float) $tat - ($interval * ($burst - 1) / 1_000_000) > $threshold) {
                                $ready = false;
                            }
                        }
                        if (RowValues::colInt($budget, 'inflight') >= $maxInflight) {
                            $ready = false;
                        }
                        if (RowValues::colNullableInt($budget, 'quota_exhausted') !== null) {
                            $reasons[] = 'quota_exhausted';
                            $ready = false;
                        }
                        if ((RowValues::colNullableInt($budget, 'hour_limit') !== null && RowValues::colNullableInt($budget, 'hour_used') === null)
                            || (RowValues::colNullableInt($budget, 'day_limit') !== null && RowValues::colNullableInt($budget, 'day_used') === null)) {
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
