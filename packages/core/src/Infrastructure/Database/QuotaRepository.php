<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use LogicException;
use Packman\Attributes\Api;
use Proxyma\Application\Admission\TrafficSharePolicy;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\DemandWait;
use Proxyma\Domain\Admission\ReservationDeferred;
use Proxyma\Domain\Budget\FixedWindow;
use Proxyma\Domain\Execution\RetryAt;
use Proxyma\Domain\Policy\BudgetConstraint;
use Proxyma\Internal\Database\RowValues;
use stdClass;

/**
 * @phpstan-type QuotaWindowPlanEntry array{
 *     id: string,
 *     budget_id: string,
 *     owner: string,
 *     kind: string,
 *     policy: string,
 *     limit: int,
 *     starts: string,
 *     ends: string,
 *     reserved: int,
 *     spent: int,
 * }
 */
#[Api(since: '1.0')]
final readonly class QuotaRepository
{
    public function __construct(private ConnectionScope $scope, private TrafficSharePolicy $traffic = new TrafficSharePolicy) {}

    /**
     * @param  list<stdClass>  $budgets
     * @param  string|list<string>|null  $permitId
     */
    public function lockWindows(array $budgets, string|array|null $permitId = null): void
    {
        $t = PackageSchema::tables($this->scope->connection);
        $old = [];
        if ($permitId !== null) {
            $permitIds = is_array($permitId) ? $permitId : [$permitId];
            $old = RowValues::strList(
                $this->scope->connection->table($t['quota_reservations'])->whereIn('permit_id', $permitIds)->pluck('window_id')->all(),
            );
        }
        $budgetIds = array_map(static fn (stdClass $b): string => RowValues::colStr($b, 'id'), $budgets);
        $rows = $this->scope->connection->table($t['quota_windows'])->whereIn('budget_id', $budgetIds)
            ->where(static function (Builder $q) use ($old): void {
                $q->whereRaw('ends_at > clock_timestamp()')->orWhereIn('id', $old);
            })->orderBy('id')->limit(257)->lockForUpdate()->get();
        if ($rows->count() > 256) {
            throw new AdmissionRejected('quota_window_bound');
        }
    }

    /**
     * Advisory eligibility for FIFO's complete predecessor sets; parent rows are already locked.
     *
     * @param  list<stdClass>  $budgets
     * @return array<string, int>
     */
    public function availability(array $budgets, DateTimeImmutable $now): array
    {
        $byId = [];
        foreach ($budgets as $budget) {
            $byId[RowValues::colStr($budget, 'id')] = $budget;
        }
        $result = [];
        $rows = PackageSchema::query($this->scope->connection, 'quota_windows')->whereIn('budget_id', array_keys($byId))
            ->where('starts_at', '<=', $now->format('Y-m-d H:i:s.uP'))->where('ends_at', '>', $now->format('Y-m-d H:i:s.uP'))->get();
        foreach ($rows as $row) {
            $budget = $byId[RowValues::colStr($row, 'budget_id')];
            $limit = RowValues::colNullableInt($budget, RowValues::colStr($row, 'window_kind').'_limit');
            if ($limit === null) {
                continue;
            }
            $limit = min($limit, RowValues::colInt($row, 'quota_limit'));
            if (RowValues::colInt($row, 'spent') >= $limit - RowValues::colInt($row, 'reserved')) {
                $budgetId = RowValues::colStr($budget, 'id');
                $result[$budgetId] = max($result[$budgetId] ?? 0, DatabaseClock::micros(new DateTimeImmutable(RowValues::colStr($row, 'ends_at'))));
            }
        }

        return $result;
    }

    /** @internal Provisioning only, under the budget mutex; never called by reserve/send. */
    public static function provisionWindows(Connection $db, object $budget, DateTimeImmutable $now, bool $reconcileHistory = false): void
    {
        if ($db->transactionLevel() < 1) {
            throw new LogicException('Quota provisioning requires a catalog transaction.');
        }
        $table = PackageSchema::table('quota_windows', $db);
        $tenantKey = RowValues::colStr($budget, 'tenant_key');
        $budgetId = RowValues::colStr($budget, 'id');
        $quotaPolicyKey = RowValues::colStr($budget, 'quota_policy_key');
        foreach (['hour', 'day'] as $kind) {
            $limit = RowValues::colNullableInt($budget, $kind.'_limit');
            if ($limit === null) {
                continue;
            }
            $current = new FixedWindow($kind, $now);
            foreach ([$current, new FixedWindow($kind, $current->endsAt)] as $window) {
                $identity = [
                    'tenant_key' => $tenantKey,
                    'budget_id' => $budgetId,
                    'window_kind' => $kind,
                    'starts_at' => $window->startsAt->format('Y-m-d H:i:s.uP'),
                    'policy_key' => $quotaPolicyKey,
                ];
                $created = $db->table($table)->insertOrIgnore($identity + ['ends_at' => $window->endsAt->format('Y-m-d H:i:s.uP'), 'quota_limit' => $limit]);
                $db->table($table)->where($identity)->update(['quota_limit' => $limit]);
                if ($created !== 0 || $reconcileHistory) {
                    self::seedHistory($db, $budget, RowValues::object($db->table($table)->where($identity)->first()), $now);
                }
            }
        }
    }

    private static function seedHistory(Connection $db, object $budget, object $window, DateTimeImmutable $now): void
    {
        $t = PackageSchema::tables($db);
        $budgetId = RowValues::colStr($budget, 'id');
        $tenantKey = RowValues::colStr($budget, 'tenant_key');
        $parent = RowValues::colNullableStr($budget, 'traffic_parent_id') ?? $budgetId;
        $class = RowValues::colNullableStr($budget, 'traffic_class');
        $windowId = RowValues::colStr($window, 'id');
        $startsAt = RowValues::colStr($window, 'starts_at');
        $endsAt = RowValues::colStr($window, 'ends_at');
        $rows = $db->select("INSERT INTO {$t['quota_reservations']} (tenant_key,permit_id,budget_tenant_key,budget_id,window_id,cost,state)
            SELECT p.tenant_key,p.id,?, ?, ?,r.cost,CASE WHEN p.authorized_at IS NOT NULL THEN 'consumed' ELSE 'reserved' END
            FROM {$t['reservations']} r JOIN {$t['permits']} p ON p.id=r.permit_id JOIN {$t['demands']} d ON d.id=p.demand_id
            WHERE r.budget_tenant_key=? AND r.budget_id=? AND (?::text IS NULL OR d.traffic_class=?)
                AND ((p.authorized_at>=?::timestamptz AND p.authorized_at<?::timestamptz)
                    OR (r.budget_id=? AND p.authorized_at IS NULL AND p.state='reserved' AND r.state='reserved'
                        AND p.not_before<?::timestamptz AND p.expires_at>?::timestamptz AND p.expires_at>?::timestamptz))
            ON CONFLICT (tenant_key,permit_id,window_id) DO NOTHING RETURNING state,cost",
            [$tenantKey, $budgetId, $windowId, $tenantKey, $parent, $class, $class,
                $startsAt, $endsAt, $budgetId, $endsAt, $startsAt, $now->format('Y-m-d H:i:s.uP')]);
        $reserved = 0;
        $spent = 0;
        foreach ($rows as $row) {
            $rowObject = RowValues::object($row);
            $cost = RowValues::colInt($rowObject, 'cost');
            if (RowValues::colStr($rowObject, 'state') === 'consumed') {
                $spent += $cost;
            } else {
                $reserved += $cost;
            }
        }
        if ($reserved !== 0) {
            $db->table($t['quota_windows'])->where('id', $windowId)->increment('reserved', $reserved);
        }
        if ($spent !== 0) {
            $db->table($t['quota_windows'])->where('id', $windowId)->increment('spent', $spent);
        }
    }

    /**
     * Parent budget rows are locked before all window rows. No lazy materialization.
     *
     * @param  list<stdClass>  $budgets
     * @param  array<string, BudgetConstraint>  $constraints
     * @return array<string, QuotaWindowPlanEntry>
     */
    public function plan(array $budgets, array $constraints, DateTimeImmutable $now, string $trafficClass, ?string $originScope = null): array
    {
        $byId = [];
        foreach ($budgets as $budget) {
            $byId[RowValues::colStr($budget, 'id')] = $budget;
        }
        if ($originScope !== null) {
            $parents = array_keys(array_filter($byId, static fn (stdClass $row): bool => RowValues::colStr($row, 'scope_key') === $originScope));
            $persisted = RowValues::strList(
                PackageSchema::query($this->scope->connection, 'budgets')->whereIn('traffic_parent_id', $parents)
                    ->where('traffic_class', $trafficClass)->pluck('id')->all(),
            );
            if (array_diff($persisted, array_keys($byId)) !== []) {
                throw new AdmissionRejected('mandatory_budget_set_changed');
            }
        }
        if ($originScope !== null && array_key_exists($trafficClass, $this->traffic->classes)) {
            foreach ($budgets as $parent) {
                $parentId = RowValues::colStr($parent, 'id');
                if (RowValues::colStr($parent, 'scope_key') === $originScope
                    && count(array_filter($budgets, static fn (stdClass $child): bool => RowValues::colNullableStr($child, 'traffic_parent_id') === $parentId && RowValues::colNullableStr($child, 'traffic_class') === $trafficClass)) !== 1) {
                    throw new AdmissionRejected('traffic_materialization_missing');
                }
            }
        }
        $result = [];
        foreach ($budgets as $budget) {
            $budgetId = RowValues::colStr($budget, 'id');
            $tenantKey = RowValues::colStr($budget, 'tenant_key');
            $scopeKey = RowValues::colStr($budget, 'scope_key');
            $quotaPolicyKey = RowValues::colStr($budget, 'quota_policy_key');
            $trafficParentId = RowValues::colNullableStr($budget, 'traffic_parent_id');
            foreach (['hour', 'day'] as $kind) {
                $limit = RowValues::colNullableInt($budget, $kind.'_limit');
                $constraint = $constraints[$scopeKey] ?? null;
                $narrow = $kind === 'hour' ? $constraint?->hourLimit : $constraint?->dayLimit;
                if ($narrow !== null) {
                    $limit = $limit === null ? $narrow : min($limit, $narrow);
                }
                if ($kind === 'hour' && $trafficParentId !== null) {
                    $parent = $byId[$trafficParentId] ?? throw new AdmissionRejected('traffic_parent_missing');
                    if (RowValues::colNullableStr($budget, 'traffic_class') !== $trafficClass) {
                        throw new AdmissionRejected('traffic_class_conflict');
                    }
                    $percent = $this->traffic->classes[$trafficClass] ?? throw new AdmissionRejected('traffic_policy_changed');
                    $derived = TrafficSharePolicy::hourlyLimit(
                        RowValues::colInt($parent, 'emission_interval_us'),
                        min($percent, RowValues::colInt($budget, 'traffic_percent')),
                    );
                    $limit = $limit === null ? $derived : min($limit, $derived);
                }
                if ($limit === null) {
                    continue;
                }
                $window = new FixedWindow($kind, $now);
                $row = PackageSchema::query($this->scope->connection, 'quota_windows')->where('tenant_key', $tenantKey)
                    ->where('budget_id', $budgetId)->where('window_kind', $kind)->where('policy_key', $quotaPolicyKey)
                    ->where('starts_at', $window->startsAt->format('Y-m-d H:i:s.uP'))->first()
                    ?? throw new AdmissionRejected('quota_materialization_missing');
                $rowId = RowValues::colStr($row, 'id');
                $effective = min(RowValues::colInt($row, 'quota_limit'), $limit);
                $result[$rowId] = [
                    'id' => $rowId,
                    'budget_id' => $budgetId,
                    'owner' => $tenantKey,
                    'kind' => $kind,
                    'policy' => $quotaPolicyKey,
                    'limit' => $effective,
                    'starts' => $window->startsAt->format('Y-m-d H:i:s.uP'),
                    'ends' => $window->endsAt->format('Y-m-d H:i:s.uP'),
                    'reserved' => RowValues::colInt($row, 'reserved'),
                    'spent' => RowValues::colInt($row, 'spent'),
                ];
            }
        }
        ksort($result);

        return $result;
    }

    /** @param array<string, QuotaWindowPlanEntry> $plan */
    public function assertAvailable(array $plan): void
    {
        $retry = null;
        $t = PackageSchema::tables($this->scope->connection);
        foreach ($plan as $row) {
            if ($row['spent'] >= $row['limit'] || $row['reserved'] >= $row['limit'] - $row['spent']) {
                $at = new DateTimeImmutable($row['ends']);
                if ($row['reserved'] > 0 && $row['spent'] < $row['limit']) {
                    $lease = $this->scope->connection->table($t['quota_reservations'].' as q')->join($t['permits'].' as p', 'p.id', '=', 'q.permit_id')
                        ->where('q.window_id', $row['id'])->where('q.state', 'reserved')->min('p.expires_at');
                    if ($lease !== null) {
                        $at = min($at, new DateTimeImmutable(RowValues::str($lease)));
                    }
                }
                $retry = $retry === null ? $at : max($retry, $at);
            }
        }
        if ($retry !== null) {
            throw new ReservationDeferred(new DemandWait('quota_exhausted', new RetryAt($retry)));
        }
    }

    /** @param array<string, QuotaWindowPlanEntry> $plan */
    public function reserve(object $permit, array $plan): void
    {
        $db = $this->scope->connection;
        $t = PackageSchema::tables($db);
        $tenantKey = RowValues::colStr($permit, 'tenant_key');
        $permitId = RowValues::colStr($permit, 'id');
        foreach ($plan as $row) {
            $changed = $db->table($t['quota_windows'])->where('id', $row['id'])
                ->whereRaw('reserved < ?::bigint - spent', [$row['limit']])->whereRaw('ends_at > clock_timestamp()')->increment('reserved');
            if ($changed !== 1) {
                throw new AdmissionRejected('quota_reservation_race');
            }
            $db->table($t['quota_reservations'])->insert([
                'tenant_key' => $tenantKey,
                'permit_id' => $permitId,
                'budget_tenant_key' => $row['owner'],
                'budget_id' => $row['budget_id'],
                'window_id' => $row['id'],
                'cost' => 1,
                'state' => 'reserved',
            ]);
        }
    }

    /** @return list<stdClass> */
    public function references(string $permitId): array
    {
        $t = PackageSchema::tables($this->scope->connection);

        return array_values($this->scope->connection->table($t['quota_reservations'].' as r')->join($t['quota_windows'].' as w', 'w.id', '=', 'r.window_id')
            ->where('r.permit_id', $permitId)->orderBy('w.budget_id')->orderBy('w.window_kind')->orderBy('w.starts_at')
            ->select(['r.*', 'w.starts_at', 'w.ends_at', 'w.window_kind', 'w.policy_key', 'w.quota_limit'])->get()->all());
    }

    public function consume(string $permitId, DateTimeImmutable $authorizedAt): void
    {
        $db = $this->scope->connection;
        $t = PackageSchema::tables($db);
        foreach ($this->references($permitId) as $ref) {
            if (RowValues::colStr($ref, 'state') !== 'reserved') {
                throw new AdmissionRejected('quota_already_consumed');
            }
            $cost = RowValues::colInt($ref, 'cost');
            $windowId = RowValues::colStr($ref, 'window_id');
            $changed = $db->table($t['quota_windows'])->where('id', $windowId)->where('reserved', '>=', $cost)
                ->where('starts_at', '<=', $authorizedAt->format('Y-m-d H:i:s.uP'))->where('ends_at', '>', $authorizedAt->format('Y-m-d H:i:s.uP'))
                ->decrement('reserved', $cost);
            if ($changed !== 1) {
                throw new AdmissionRejected('quota_accounting_conflict');
            }
            $db->table($t['quota_windows'])->where('id', $windowId)->increment('spent', $cost);
            $db->table($t['quota_reservations'])->where('id', RowValues::colStr($ref, 'id'))->where('state', 'reserved')->update(['state' => 'consumed']);
        }
    }

    public function release(string $permitId): void
    {
        $db = $this->scope->connection;
        $t = PackageSchema::tables($db);
        foreach ($this->references($permitId) as $ref) {
            if (RowValues::colStr($ref, 'state') !== 'reserved') {
                continue;
            }
            $cost = RowValues::colInt($ref, 'cost');
            if ($db->table($t['quota_windows'])->where('id', RowValues::colStr($ref, 'window_id'))->where('reserved', '>=', $cost)->decrement('reserved', $cost) !== 1) {
                throw new AdmissionRejected('quota_release_conflict');
            }
            $db->table($t['quota_reservations'])->where('id', RowValues::colStr($ref, 'id'))->update(['state' => 'released']);
        }
    }
}
