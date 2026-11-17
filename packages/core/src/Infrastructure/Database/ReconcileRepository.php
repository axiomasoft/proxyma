<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use DateTimeImmutable;
use Packman\Attributes\Api;
use Proxyma\Application\Admission\AuthorityLocker;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\AuthorityLockTier;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\PermitState;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Internal\Database\RowValues;
use stdClass;

/**
 * @phpstan-type ReconcileState array{
 *     permits: list<stdClass>,
 *     demands: list<stdClass>,
 *     attempts: list<stdClass>,
 *     budgets: list<stdClass>,
 *     references: list<stdClass>,
 *     epochs: array<string, int>,
 * }
 */
#[Api(since: '1.0')]
final readonly class ReconcileRepository
{
    public function __construct(private ConnectionScope $scope, private AuthorityLocker $locks, private DemandRepository $demands,
        private BudgetRepository $budgets, private QuotaRepository $quotas) {}

    /** Root is the first package mutex. Candidate reads never lock permits. */
    public function root(TenantKey $tenant, string $id, LockedTransactionContext $tx, bool $skip = false): ?object
    {
        $this->locks->enter(AuthorityLockTier::Operation, $id."\0".$tenant->value, $tx);

        return $this->scope->connection->table(PackageSchema::tables()['operations'])->where('tenant_key', $tenant->value)->where('id', $id)
            ->lock($skip ? 'FOR UPDATE SKIP LOCKED' : 'FOR UPDATE')->first();
    }

    /**
     * Compatible completion/recovery subset; versions may be retired, never revalidated as send authority.
     *
     * @return ReconcileState
     */
    public function lockSubset(object $root, LockedTransactionContext $tx): array
    {
        $db = $this->scope->connection;
        $t = PackageSchema::tables();
        $rootId = RowValues::colStr($root, 'id');
        $rootProfileId = RowValues::colStr($root, 'profile_id');
        $rootTargetId = RowValues::colStr($root, 'target_id');
        $tenant = new TenantKey(RowValues::colStr($root, 'tenant_key'));
        $permits = array_values($db->table($t['permits'])->where('operation_id', $rootId)->orderBy('id')->limit(257)->get()->all());
        $demands = array_values($db->table($t['demands'])->where('operation_id', $rootId)->orderBy('id')->limit(257)->get()->all());
        if (count($permits) > 256 || count($demands) > 256) {
            throw new AdmissionRejected('recovery_operation_bound');
        }
        $ids = array_map(static fn (stdClass $p): string => RowValues::colStr($p, 'id'), $permits);
        $heads = [];
        $headKeys = [];
        $pinnedEndpoints = [];
        foreach ($permits as $permit) {
            $evidence = RowValues::jsonAssoc(RowValues::colStr($permit, 'reservation_evidence'));
            $versions = array_key_exists('versions', $evidence) ? RowValues::assoc($evidence['versions']) : [];
            foreach (array_keys($versions) as $key) {
                if (str_starts_with($key, 'endpoint:')) {
                    $pinnedEndpoints[] = substr($key, strlen('endpoint:'));
                }
                if (str_starts_with($key, 'head:')) {
                    $headKeys[] = $key;
                }
            }
        }
        foreach ($db->table($t['policy_heads'])->whereIn('tenant_key', array_unique([$tenant->value, TenantKey::SYSTEM]))
            ->whereRaw("('head:' || tenant_key || ':' || scope_key) = ANY(?)", ['{'.implode(',', array_map(static fn (string $k): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $k).'"', array_values(array_unique($headKeys)))).'}'])
            ->get() as $head) {
            $headScopeKey = RowValues::colStr($head, 'scope_key');
            $headTenantKey = RowValues::colStr($head, 'tenant_key');
            $heads[$headScopeKey."\0".$headTenantKey] = [$headTenantKey, $headScopeKey];
        }
        ksort($heads, SORT_STRING);
        $this->locks->policyRows(array_map(
            static fn (array $head): array => ['tenant' => new TenantKey($head[0]), 'scopeKey' => $head[1]],
            array_values($heads),
        ), $tx);
        $this->locks->catalogueRows(AuthorityLockTier::Profile, $tenant, [$rootProfileId], $tx);
        $routeIds = array_map(static fn (stdClass $p): string => RowValues::colStr($p, 'route_id'), $permits);
        $routes = array_values($db->table($t['routes'])->whereIn('id', $routeIds)->get()->all());
        $routeEndpointIds = array_values(array_filter(
            array_map(static fn (stdClass $r): ?string => RowValues::colNullableStr($r, 'endpoint_id'), $routes),
            is_string(...),
        ));
        $endpoints = array_values($db->table($t['endpoints'])->whereIn('id', array_values(array_unique([...$pinnedEndpoints, ...$routeEndpointIds])))->get()->all());
        $fetchedRouteIds = array_map(static fn (stdClass $r): string => RowValues::colStr($r, 'id'), $routes);
        $tiers = [
            [AuthorityLockTier::Endpoint, $endpoints],
            [AuthorityLockTier::Route, $routes],
        ];
        foreach (['route_health' => AuthorityLockTier::RouteHealth, 'route_target_health' => AuthorityLockTier::RouteTargetHealth] as $table => $tier) {
            $query = $db->table($t[$table])->whereIn('route_id', $fetchedRouteIds);
            if ($table === 'route_target_health') {
                $query->where('target_id', $rootTargetId);
            }
            $tiers[] = [$tier, array_values($query->get()->all())];
        }
        $tiers[] = [AuthorityLockTier::Target, array_values($db->table($t['targets'])->where('id', $rootTargetId)->get()->all())];
        $tiers[] = [AuthorityLockTier::TargetHealth, array_values($db->table($t['target_health'])->where('tenant_key', $tenant->value)->where('target_id', $rootTargetId)->get()->all())];
        $epochs = [];
        foreach ($tiers as [$tier, $rows]) {
            usort($rows, static fn (stdClass $a, stdClass $b): int => strcmp(RowValues::colStr($a, 'id'), RowValues::colStr($b, 'id')));
            foreach ($rows as $row) {
                $rowId = RowValues::colStr($row, 'id');
                $rowTenant = new TenantKey(RowValues::colStr($row, 'tenant_key'));
                $locked = in_array($tier, [AuthorityLockTier::Endpoint, AuthorityLockTier::Route, AuthorityLockTier::Target], true)
                    ? $this->locks->catalogueRows($tier, $rowTenant, [$rowId], $tx)[0]
                    : $this->locks->rows($tier, $rowTenant, [$rowId], $tx)[0];
                $healthEpoch = RowValues::colNullableInt($locked, 'health_epoch');
                if ($healthEpoch !== null) {
                    $name = match ($tier) {
                        AuthorityLockTier::RouteHealth => 'route_health',
                        AuthorityLockTier::RouteTargetHealth => 'route_target_health',
                        default => 'target_health',
                    };
                    $epochs[$name.':'.$rowId] = $healthEpoch;
                }
            }
        }
        $demandIds = array_map(static fn (stdClass $d): string => RowValues::colStr($d, 'id'), $demands);
        $references = array_values($db->table($t['reservations'])->whereIn('permit_id', $ids)->get()->all());
        $quotaRefs = array_values($db->table($t['quota_reservations'])->whereIn('permit_id', $ids)->get()->all());
        $demandRefs = array_values($db->table($t['demand_scopes'])->whereIn('demand_id', $demandIds)->get()->all());
        $budgetIds = array_values(array_unique([
            ...array_map(static fn (stdClass $r): string => RowValues::colStr($r, 'budget_id'), $references),
            ...array_map(static fn (stdClass $r): string => RowValues::colStr($r, 'budget_id'), $quotaRefs),
            ...array_map(static fn (stdClass $r): string => RowValues::colStr($r, 'budget_id'), $demandRefs),
        ]));
        $budgets = $this->demands->lockBudgets(array_values($db->table($t['budgets'])->whereIn('id', $budgetIds)->get()->all()), $tx);
        $this->quotas->lockWindows($budgets, $ids);
        $this->locks->rows(AuthorityLockTier::Demand, $tenant, $demandIds, $tx);
        $permits = $this->locks->rows(AuthorityLockTier::Permit, $tenant, $ids, $tx);
        $attempts = array_values($db->table($t['attempts'])->whereIn('permit_id', $ids)->get()->all());
        $attempts = $this->locks->rows(
            AuthorityLockTier::Attempt,
            $tenant,
            array_map(static fn (stdClass $a): string => RowValues::colStr($a, 'id'), $attempts),
            $tx,
        );

        return [
            'permits' => $permits,
            'demands' => $demands,
            'attempts' => $attempts,
            'budgets' => $budgets,
            'references' => $references,
            'epochs' => $epochs,
        ];
    }

    /** @param ReconcileState $state */
    public function release(object $permit, array $state): void
    {
        if (RowValues::colNullableStr($permit, 'lease_released_at') !== null) {
            return;
        }
        $permitId = RowValues::colStr($permit, 'id');
        $this->quotas->release($permitId);
        $this->budgets->release(
            array_values(array_filter($state['references'], static fn (stdClass $r): bool => RowValues::colStr($r, 'permit_id') === $permitId)),
            $state['budgets'],
        );
        $this->scope->connection->table(PackageSchema::tables()['permits'])->where('id', $permitId)->update(['lease_released_at' => $this->scope->connection->raw('clock_timestamp()')]);
    }

    /** @param ReconcileState $state */
    public function expire(object $root, array $state): int
    {
        $db = $this->scope->connection;
        $t = PackageSchema::tables();
        $now = DatabaseClock::now($db);
        $dead = RowValues::colStr($root, 'status') !== 'active'
            || RowValues::colNullableStr($root, 'revoked_at') !== null
            || new DateTimeImmutable(RowValues::colStr($root, 'deadline')) <= $now;
        $changed = 0;
        foreach ($state['permits'] as $permit) {
            $permitId = RowValues::colStr($permit, 'id');
            $status = PermitState::from(RowValues::colStr($permit, 'state'));
            if (($status === PermitState::Reserved && $dead) || new DateTimeImmutable(RowValues::colStr($permit, 'expires_at')) <= $now) {
                if ($status === PermitState::Reserved) {
                    $status->transitionTo(PermitState::ExpiredBeforeSend);
                    $db->table($t['permits'])->where('id', $permitId)->where('state', 'reserved')->update(['state' => 'expired_before_send']);
                    $db->table($t['demands'])->where('id', RowValues::colStr($permit, 'demand_id'))->update(['state' => 'expired']);
                    $changed++;
                } elseif ($status === PermitState::Authorized) {
                    $status->transitionTo(PermitState::UnknownAfterAuthorization);
                    $db->table($t['permits'])->where('id', $permitId)->where('state', 'authorized')->update(['state' => 'unknown_after_authorization']);
                    $db->table($t['attempts'])->where('permit_id', $permitId)->whereNull('receipt_key')->update(['status' => 'unknown_after_authorization']);
                    $changed++;
                }
                if (RowValues::colNullableStr($permit, 'lease_released_at') === null) {
                    $this->release($permit, $state);
                    $changed++;
                }
            }
        }
        foreach ($state['demands'] as $demand) {
            if (RowValues::colStr($demand, 'state') === 'active'
                && ($dead || min(new DateTimeImmutable(RowValues::colStr($demand, 'deadline')), new DateTimeImmutable(RowValues::colStr($demand, 'expires_at'))) <= $now)) {
                $changed += $db->table($t['demands'])->where('id', RowValues::colStr($demand, 'id'))->where('state', 'active')->update(['state' => 'expired']);
            }
        }

        return $changed;
    }
}
