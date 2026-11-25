<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Packman\Attributes\Api;
use Proxyma\Application\Admission\AuthorityLocker;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Budgets\BudgetKey;
use Proxyma\Domain\Execution\DemandState;
use Proxyma\Domain\Execution\DemandTicket;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\RouteSelection;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Internal\Database\RowValues;
use stdClass;

/** @internal Row work only; the application service owns the transaction. */
#[Api(since: '1.0')]
final readonly class DemandRepository
{
    public function __construct(private ConnectionScope $scope, private AuthorityLocker $locks) {}

    /**
     * @param  list<string>  $additionalScopes
     * @return list<stdClass>
     */
    public function resolveBudgets(TenantKey $tenant, WireRequest $request, RouteSelection $route, array $additionalScopes): array
    {
        $t = PackageSchema::tables($this->scope->connection);
        $catalog = $this->scope->connection->table($t['route_profile_members'].' as m')
            ->join($t['routes'].' as r', fn (JoinClause $j): JoinClause => $j->on('r.id', '=', 'm.route_id')->on('r.tenant_key', '=', 'm.route_owner_tenant_key'))
            ->where('m.tenant_key', $tenant->value)->where('m.profile_id', $route->profileId?->value)->where('m.route_id', $route->routeId->value)
            ->select('r.*')->first();
        if ($catalog === null || RowValues::colStr($catalog, 'egress_key') !== $route->egress->key
            || RowValues::colBool($catalog, 'conservative_pool') !== $route->egress->conservativePoolFallback) {
            throw new AdmissionRejected('route_scope_snapshot_changed');
        }
        $origin = $request->url->origin;
        $required = [$origin->budgetScopeKey(), ...$additionalScopes];
        $egress = $origin->egressScopeKey($route->egress);
        $catalogEgressKey = RowValues::colStr($catalog, 'egress_key');
        $catalogId = RowValues::colStr($catalog, 'id');
        $sharedEgress = $this->scope->connection->table($t['routes'])->where('egress_key', $catalogEgressKey)->where('id', '<>', $catalogId)->exists();
        if ($route->egress->conservativePoolFallback || $sharedEgress) {
            $required[] = $egress;
        }
        $keys = array_values(array_unique([...$required, $egress, $origin->routeScopeKey($route->routeId)]));
        if (count($keys) > 16) {
            throw new AdmissionRejected('too_many_budget_scopes');
        }
        foreach ($keys as $key) {
            new BudgetKey($tenant, $key);
        }
        $rows = array_values(PackageSchema::query($this->scope->connection, 'budgets')->whereIn('tenant_key', array_unique([$tenant->value, TenantKey::SYSTEM]))
            ->whereIn('scope_key', $keys)->orderByRaw('scope_key COLLATE "C", tenant_key COLLATE "C"')->get()->all());
        $found = array_map(static fn (stdClass $r): string => RowValues::colStr($r, 'scope_key'), $rows);
        if (array_diff($required, $found) !== []) {
            throw new AdmissionRejected('required_budget_missing');
        }

        return $rows;
    }

    /**
     * @param  list<stdClass>  $rows
     * @return list<stdClass>
     */
    public function lockBudgets(array $rows, LockedTransactionContext $tx): array
    {
        $unique = [];
        foreach ($rows as $row) {
            $key = new BudgetKey(new TenantKey(RowValues::colStr($row, 'tenant_key')), RowValues::colStr($row, 'scope_key'));
            $unique[$key->orderKey()] = $row;
        }
        ksort($unique, SORT_STRING);

        return $this->locks->budgetRows(array_values($unique), $tx);
    }

    public function byAttempt(TenantKey $tenant, Uuid $operation, Uuid $attempt): ?stdClass
    {
        return PackageSchema::query($this->scope->connection, 'demands')->where('tenant_key', $tenant->value)
            ->where('operation_id', $operation->value)->where('physical_attempt_id', $attempt->value)->first();
    }

    /** @param list<stdClass> $budgets */
    public function insert(TenantKey $tenant, WireRequest $request, Uuid $profile, string $purpose, DateTimeImmutable $deadline,
        DateTimeImmutable $expires, string $intent, array $budgets): stdClass
    {
        $db = $this->scope->connection;
        $t = PackageSchema::tables($db);
        $id = Uuid::random();
        $scopeKeys = array_map(static fn (stdClass $budget): string => RowValues::colStr($budget, 'tenant_key').':'.RowValues::colStr($budget, 'id'), $budgets);
        sort($scopeKeys, SORT_STRING);
        $db->table($t['demands'])->insert(['id' => $id->value, 'tenant_key' => $tenant->value, 'operation_id' => $request->operationId->value,
            'profile_id' => $profile->value, 'target_id' => $request->targetId->value, 'purpose' => $purpose, 'cost' => 1, 'traffic_class' => $request->trafficClass,
            'deadline' => $deadline->format('Y-m-d H:i:s.uP'), 'expires_at' => $expires->format('Y-m-d H:i:s.uP'), 'physical_attempt_id' => $request->physicalAttemptId->value,
            'wire_fingerprint' => $request->fingerprint()->hex, 'intent_hash' => $intent, 'scope_hash' => hash('sha256', implode(',', $scopeKeys))]);
        foreach ($budgets as $budget) {
            $db->table($t['demand_scopes'])->insert(['tenant_key' => $tenant->value, 'demand_id' => $id->value,
                'budget_tenant_key' => RowValues::colStr($budget, 'tenant_key'), 'budget_id' => RowValues::colStr($budget, 'id')]);
        }

        return $this->byAttempt($tenant, $request->operationId, $request->physicalAttemptId)
            ?? throw new AdmissionRejected('demand_missing');
    }

    /**
     * @param  list<string>  $budgetIds
     * @return list<stdClass>
     */
    public function predecessors(stdClass $candidate, array $budgetIds): array
    {
        $t = PackageSchema::tables($this->scope->connection);

        return array_values($this->scope->connection->table($t['demands'].' as d')->join($t['operations'].' as o', 'o.id', '=', 'd.operation_id')
            ->where('d.state', 'active')->whereNotNull('d.physical_attempt_id')->whereRaw('(d.sequence, d.id) < (?, ?::uuid)', [RowValues::colInt($candidate, 'sequence'), RowValues::colStr($candidate, 'id')])
            ->where('o.status', 'active')->whereNull('o.revoked_at')->whereRaw('least(d.deadline, d.expires_at, o.deadline) > clock_timestamp()')
            ->whereExists(function (Builder $q) use ($t, $budgetIds): void {
                $q->selectRaw('1')->from($t['demand_scopes'].' as s')->whereColumn('s.demand_id', 'd.id')->whereIn('s.budget_id', $budgetIds);
            })->select('d.*')->orderBy('d.sequence')->orderBy('d.id')->limit(1025)->get()->all());
    }

    /**
     * @param  list<string>  $demandIds
     * @return array<string, list<stdClass>>
     */
    public function scopes(array $demandIds): array
    {
        $t = PackageSchema::tables($this->scope->connection);
        $result = [];
        $rows = $this->scope->connection->table($t['demand_scopes'].' as s')->join($t['budgets'].' as b', 'b.id', '=', 's.budget_id')
            ->whereIn('s.demand_id', $demandIds)->select(['b.*', 's.demand_id'])->get();
        foreach ($rows as $row) {
            $result[RowValues::colStr($row, 'demand_id')][] = $row;
        }

        return $result;
    }

    public function ticket(stdClass $row, string $limitingScope): DemandTicket
    {
        return new DemandTicket(
            new Uuid(RowValues::colStr($row, 'id')),
            new Uuid(RowValues::colStr($row, 'operation_id')),
            new Uuid(RowValues::colStr($row, 'physical_attempt_id')),
            RowValues::colInt($row, 'sequence'),
            $limitingScope,
            new DateTimeImmutable(RowValues::colStr($row, 'expires_at')),
            new DateTimeImmutable(RowValues::colStr($row, 'deadline')),
            DemandState::from(RowValues::colStr($row, 'state')),
        );
    }

    public function pendingForBudget(string $budgetId): object
    {
        $t = PackageSchema::tables($this->scope->connection);

        return $this->scope->connection->table($t['demands'].' as d')->join($t['demand_scopes'].' as s', 's.demand_id', '=', 'd.id')
            ->join($t['operations'].' as o', 'o.id', '=', 'd.operation_id')->where('s.budget_id', $budgetId)
            ->whereIn('d.state', ['active', 'admitted'])->where('o.status', 'active')->whereNull('o.revoked_at')
            ->whereRaw('least(d.deadline, d.expires_at, o.deadline) > clock_timestamp()')
            ->selectRaw('count(*) AS pending, min(least(d.deadline, d.expires_at, o.deadline)) AS retry_at')->first()
            ?? (object) ['pending' => 0, 'retry_at' => null];
    }

    public function state(stdClass $row, string $state): void
    {
        PackageSchema::query($this->scope->connection, 'demands')
            ->where('tenant_key', RowValues::colStr($row, 'tenant_key'))
            ->where('id', RowValues::colStr($row, 'id'))
            ->update(['state' => $state]);
        $row->state = $state;
    }

    /**
     * @param  list<stdClass>  $budgets
     * @return array<string, DateTimeImmutable>
     */
    public function availability(array $budgets, int $retryMs): array
    {
        $t = PackageSchema::tables($this->scope->connection);
        $result = [];
        foreach ($budgets as $budget) {
            $budgetId = RowValues::colStr($budget, 'id');
            $value = RowValues::object($this->scope->connection->selectOne("SELECT ceil(extract(epoch FROM greatest(clock_timestamp(), b.cooldown_until,
                b.tat - (b.emission_interval_us * (b.burst - 1)) * interval '1 microsecond',
                b.send_tat - (b.emission_interval_us * (b.burst - 1)) * interval '1 microsecond',
                CASE WHEN b.inflight >= b.max_inflight THEN greatest(clock_timestamp() + ? * interval '1 millisecond',
                    COALESCE((SELECT min(p.expires_at) FROM {$t['reservations']} r JOIN {$t['permits']} p ON p.id = r.permit_id
                        WHERE r.budget_id = b.id AND r.state IN ('reserved','spent') AND p.state IN ('reserved','authorized','unknown_after_authorization')),
                        clock_timestamp())) ELSE clock_timestamp() END,
                COALESCE((SELECT max(q.ends_at) FROM {$t['quota_windows']} q WHERE q.budget_id = b.id
                    AND q.starts_at <= clock_timestamp() AND q.ends_at > clock_timestamp() AND q.reserved + q.spent >= q.quota_limit), clock_timestamp())
                )) * 1000000)::bigint AS ready_us FROM {$t['budgets']} b WHERE b.id = ?", [$retryMs, $budgetId]));
            $result[$budgetId] = DatabaseClock::instant(RowValues::colInt($value, 'ready_us'));
        }

        return $result;
    }
}
