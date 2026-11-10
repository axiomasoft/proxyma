<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use LogicException;
use Proxyma\Application\Admission\TrafficSharePolicy;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\DemandWait;
use Proxyma\Domain\Admission\ReservationDeferred;
use Proxyma\Domain\Budget\FixedWindow;
use Proxyma\Domain\Execution\RetryAt;

final readonly class QuotaRepository
{
    public function __construct(private ConnectionScope $scope, private TrafficSharePolicy $traffic = new TrafficSharePolicy) {}

    public function lockWindows(array $budgets, ?string $permitId = null): void
    {
        $t = PackageSchema::tables();
        $old = $permitId === null ? [] : $this->scope->connection->table($t['quota_reservations'])->where('permit_id', $permitId)->pluck('window_id')->all();
        $rows = $this->scope->connection->table($t['quota_windows'])->whereIn('budget_id', array_column($budgets, 'id'))
            ->where(fn ($q) => $q->whereRaw('ends_at > clock_timestamp()')->orWhereIn('id', $old))->orderBy('id')->limit(257)->lockForUpdate()->get();
        if ($rows->count() > 256) {
            throw new AdmissionRejected('quota_window_bound');
        }
    }

    /** Advisory eligibility for FIFO's complete predecessor sets; parent rows are already locked. */
    public function availability(array $budgets, DateTimeImmutable $now): array
    {
        $byId = array_column($budgets, null, 'id');
        $result = [];
        $rows = $this->scope->connection->table(PackageSchema::tables()['quota_windows'])->whereIn('budget_id', array_keys($byId))
            ->where('starts_at', '<=', $now->format('Y-m-d H:i:s.uP'))->where('ends_at', '>', $now->format('Y-m-d H:i:s.uP'))->get();
        foreach ($rows as $row) {
            $budget = $byId[$row->budget_id];
            $limit = $budget->{$row->window_kind.'_limit'};
            if ($limit === null) {
                continue;
            }
            $limit = min((int) $limit, (int) $row->quota_limit);
            if ((int) $row->spent >= $limit - (int) $row->reserved) {
                $result[$budget->id] = max($result[$budget->id] ?? 0, DatabaseClock::micros(new DateTimeImmutable($row->ends_at)));
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
        $table = PackageSchema::tables()['quota_windows'];
        foreach (['hour', 'day'] as $kind) {
            $limit = $budget->{$kind.'_limit'};
            if ($limit === null) {
                continue;
            }
            $current = new FixedWindow($kind, $now);
            foreach ([$current, new FixedWindow($kind, $current->endsAt)] as $window) {
                $identity = ['tenant_key' => $budget->tenant_key, 'budget_id' => $budget->id, 'window_kind' => $kind,
                    'starts_at' => $window->startsAt->format('Y-m-d H:i:s.uP'), 'policy_key' => $budget->quota_policy_key];
                $created = $db->table($table)->insertOrIgnore($identity + ['ends_at' => $window->endsAt->format('Y-m-d H:i:s.uP'), 'quota_limit' => $limit]);
                $db->table($table)->where($identity)->update(['quota_limit' => $limit]);
                if ($created !== 0 || $reconcileHistory) {
                    self::seedHistory($db, $budget, $db->table($table)->where($identity)->first(), $now);
                }
            }
        }
    }

    private static function seedHistory(Connection $db, object $budget, object $window, DateTimeImmutable $now): void
    {
        $t = PackageSchema::tables();
        $parent = $budget->traffic_parent_id ?? $budget->id;
        $class = $budget->traffic_class;
        $rows = $db->select("INSERT INTO {$t['quota_reservations']} (tenant_key,permit_id,budget_tenant_key,budget_id,window_id,cost,state)
            SELECT p.tenant_key,p.id,?, ?, ?,r.cost,CASE WHEN p.authorized_at IS NOT NULL THEN 'consumed' ELSE 'reserved' END
            FROM {$t['reservations']} r JOIN {$t['permits']} p ON p.id=r.permit_id JOIN {$t['demands']} d ON d.id=p.demand_id
            WHERE r.budget_tenant_key=? AND r.budget_id=? AND (?::text IS NULL OR d.traffic_class=?)
                AND ((p.authorized_at>=?::timestamptz AND p.authorized_at<?::timestamptz)
                    OR (r.budget_id=? AND p.authorized_at IS NULL AND p.state='reserved' AND r.state='reserved'
                        AND p.not_before<?::timestamptz AND p.expires_at>?::timestamptz AND p.expires_at>?::timestamptz))
            ON CONFLICT (tenant_key,permit_id,window_id) DO NOTHING RETURNING state,cost",
            [$budget->tenant_key, $budget->id, $window->id, $budget->tenant_key, $parent, $class, $class,
                $window->starts_at, $window->ends_at, $budget->id, $window->ends_at, $window->starts_at, $now->format('Y-m-d H:i:s.uP')]);
        $reserved = 0;
        $spent = 0;
        foreach ($rows as $row) {
            if ($row->state === 'consumed') {
                $spent += (int) $row->cost;
            } else {
                $reserved += (int) $row->cost;
            }
        }
        if ($reserved !== 0 || $spent !== 0) {
            $db->table($t['quota_windows'])->where('id', $window->id)->update([
                'reserved' => $db->raw('reserved + '.$reserved), 'spent' => $db->raw('spent + '.$spent)]);
        }
    }

    /** Parent budget rows are locked before all window rows. No lazy materialization. */
    public function plan(array $budgets, array $constraints, DateTimeImmutable $now, string $trafficClass, ?string $originScope = null): array
    {
        $byId = array_column($budgets, null, 'id');
        if ($originScope !== null) {
            $parents = array_keys(array_filter($byId, static fn ($row) => $row->scope_key === $originScope));
            $persisted = $this->scope->connection->table(PackageSchema::tables()['budgets'])->whereIn('traffic_parent_id', $parents)
                ->where('traffic_class', $trafficClass)->pluck('id')->all();
            if (array_diff($persisted, array_keys($byId)) !== []) {
                throw new AdmissionRejected('mandatory_budget_set_changed');
            }
        }
        if ($originScope !== null && array_key_exists($trafficClass, $this->traffic->classes)) {
            foreach ($budgets as $parent) {
                if ($parent->scope_key === $originScope && count(array_filter($budgets, static fn ($child) => $child->traffic_parent_id === $parent->id && $child->traffic_class === $trafficClass)) !== 1) {
                    throw new AdmissionRejected('traffic_materialization_missing');
                }
            }
        }
        $result = [];
        foreach ($budgets as $budget) {
            foreach (['hour', 'day'] as $kind) {
                $limit = $budget->{$kind.'_limit'};
                $constraint = $constraints[$budget->scope_key] ?? null;
                $narrow = $kind === 'hour' ? $constraint?->hourLimit : $constraint?->dayLimit;
                if ($narrow !== null) {
                    $limit = $limit === null ? $narrow : min((int) $limit, $narrow);
                }
                if ($kind === 'hour' && $budget->traffic_parent_id !== null) {
                    $parent = $byId[$budget->traffic_parent_id] ?? throw new AdmissionRejected('traffic_parent_missing');
                    if ($budget->traffic_class !== $trafficClass) {
                        throw new AdmissionRejected('traffic_class_conflict');
                    }
                    $percent = $this->traffic->classes[$trafficClass] ?? throw new AdmissionRejected('traffic_policy_changed');
                    $derived = TrafficSharePolicy::hourlyLimit((int) $parent->emission_interval_us, min($percent, (int) $budget->traffic_percent));
                    $limit = $limit === null ? $derived : min((int) $limit, $derived);
                }
                if ($limit === null) {
                    continue;
                }
                $window = new FixedWindow($kind, $now);
                $row = $this->scope->connection->table(PackageSchema::tables()['quota_windows'])->where('tenant_key', $budget->tenant_key)
                    ->where('budget_id', $budget->id)->where('window_kind', $kind)->where('policy_key', $budget->quota_policy_key)
                    ->where('starts_at', $window->startsAt->format('Y-m-d H:i:s.uP'))->first()
                    ?? throw new AdmissionRejected('quota_materialization_missing');
                $effective = min((int) $row->quota_limit, (int) $limit);
                $result[$row->id] = ['id' => $row->id, 'budget_id' => $budget->id, 'owner' => $budget->tenant_key,
                    'kind' => $kind, 'policy' => $budget->quota_policy_key, 'limit' => $effective,
                    'starts' => $window->startsAt->format('Y-m-d H:i:s.uP'), 'ends' => $window->endsAt->format('Y-m-d H:i:s.uP'),
                    'reserved' => (int) $row->reserved, 'spent' => (int) $row->spent];
            }
        }
        ksort($result);

        return $result;
    }

    public function assertAvailable(array $plan): void
    {
        $retry = null;
        $t = PackageSchema::tables();
        foreach ($plan as $row) {
            if ($row['spent'] >= $row['limit'] || $row['reserved'] >= $row['limit'] - $row['spent']) {
                $at = new DateTimeImmutable($row['ends']);
                if ($row['reserved'] > 0 && $row['spent'] < $row['limit']) {
                    $lease = $this->scope->connection->table($t['quota_reservations'].' as q')->join($t['permits'].' as p', 'p.id', '=', 'q.permit_id')
                        ->where('q.window_id', $row['id'])->where('q.state', 'reserved')->min('p.expires_at');
                    if ($lease !== null) {
                        $at = min($at, new DateTimeImmutable($lease));
                    }
                }
                $retry = $retry === null ? $at : max($retry, $at);
            }
        }
        if ($retry !== null) {
            throw new ReservationDeferred(new DemandWait('quota_exhausted', new RetryAt($retry)));
        }
    }

    public function reserve(object $permit, array $plan): void
    {
        $db = $this->scope->connection;
        $t = PackageSchema::tables();
        foreach ($plan as $row) {
            $changed = $db->table($t['quota_windows'])->where('id', $row['id'])
                ->whereRaw('reserved < ?::bigint - spent', [$row['limit']])->whereRaw('ends_at > clock_timestamp()')->increment('reserved');
            if ($changed !== 1) {
                throw new AdmissionRejected('quota_reservation_race');
            }
            $db->table($t['quota_reservations'])->insert(['tenant_key' => $permit->tenant_key, 'permit_id' => $permit->id,
                'budget_tenant_key' => $row['owner'], 'budget_id' => $row['budget_id'], 'window_id' => $row['id'], 'cost' => 1, 'state' => 'reserved']);
        }
    }

    public function references(string $permitId): array
    {
        $t = PackageSchema::tables();

        return $this->scope->connection->table($t['quota_reservations'].' as r')->join($t['quota_windows'].' as w', 'w.id', '=', 'r.window_id')
            ->where('r.permit_id', $permitId)->orderBy('w.budget_id')->orderBy('w.window_kind')->orderBy('w.starts_at')
            ->select(['r.*', 'w.starts_at', 'w.ends_at', 'w.window_kind', 'w.policy_key', 'w.quota_limit'])->get()->all();
    }

    public function consume(string $permitId, DateTimeImmutable $authorizedAt): void
    {
        $db = $this->scope->connection;
        $t = PackageSchema::tables();
        foreach ($this->references($permitId) as $ref) {
            if ($ref->state !== 'reserved') {
                throw new AdmissionRejected('quota_already_consumed');
            }
            $changed = $db->table($t['quota_windows'])->where('id', $ref->window_id)->where('reserved', '>=', $ref->cost)
                ->where('starts_at', '<=', $authorizedAt->format('Y-m-d H:i:s.uP'))->where('ends_at', '>', $authorizedAt->format('Y-m-d H:i:s.uP'))
                ->update(['reserved' => $db->raw('reserved - '.(int) $ref->cost), 'spent' => $db->raw('spent + '.(int) $ref->cost)]);
            if ($changed !== 1) {
                throw new AdmissionRejected('quota_accounting_conflict');
            }
            $db->table($t['quota_reservations'])->where('id', $ref->id)->where('state', 'reserved')->update(['state' => 'consumed']);
        }
    }

    public function release(string $permitId): void
    {
        $db = $this->scope->connection;
        $t = PackageSchema::tables();
        foreach ($this->references($permitId) as $ref) {
            if ($ref->state !== 'reserved') {
                continue;
            }
            if ($db->table($t['quota_windows'])->where('id', $ref->window_id)->where('reserved', '>=', $ref->cost)->decrement('reserved', (int) $ref->cost) !== 1) {
                throw new AdmissionRejected('quota_release_conflict');
            }
            $db->table($t['quota_reservations'])->where('id', $ref->id)->update(['state' => 'released']);
        }
    }
}
