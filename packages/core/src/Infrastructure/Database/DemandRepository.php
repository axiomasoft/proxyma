<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use DateTimeImmutable;
use Proxyma\Application\Admission\AuthorityLocker;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\AuthorityLockTier;
use Proxyma\Domain\Budgets\BudgetKey;
use Proxyma\Domain\Execution\DemandState;
use Proxyma\Domain\Execution\DemandTicket;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\RouteSelection;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Policy\TenantKey;
use stdClass;

/** @internal Row work only; the application service owns the transaction. */
final readonly class DemandRepository
{
    public function __construct(private ConnectionScope $scope, private AuthorityLocker $locks) {}

    /** @param list<string> $additionalScopes
     * @return list<stdClass>
     */
    public function resolveBudgets(TenantKey $tenant, WireRequest $request, RouteSelection $route, array $additionalScopes): array
    {
        $t = PackageSchema::tables();
        $catalog = $this->scope->connection->table($t['route_profile_members'].' as m')
            ->join($t['routes'].' as r', fn ($j) => $j->on('r.id', '=', 'm.route_id')->on('r.tenant_key', '=', 'm.route_owner_tenant_key'))
            ->where('m.tenant_key', $tenant->value)->where('m.profile_id', $route->profileId?->value)->where('m.route_id', $route->routeId->value)
            ->select('r.*')->first();
        if ($catalog === null || $catalog->egress_key !== $route->egress->key
            || (bool) $catalog->conservative_pool !== $route->egress->conservativePoolFallback) {
            throw new AdmissionRejected('route_scope_snapshot_changed');
        }
        $origin = $request->url->origin;
        $required = [$origin->budgetScopeKey(), ...$additionalScopes];
        $egress = $origin->egressScopeKey($route->egress);
        $sharedEgress = $this->scope->connection->table($t['routes'])->where('egress_key', $catalog->egress_key)->where('id', '<>', $catalog->id)->exists();
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
        $rows = $this->scope->connection->table(PackageSchema::tables()['budgets'])->whereIn('tenant_key', array_unique([$tenant->value, TenantKey::SYSTEM]))
            ->whereIn('scope_key', $keys)->orderByRaw('scope_key COLLATE "C", tenant_key COLLATE "C"')->get()->all();
        $found = array_column($rows, 'scope_key');
        if (array_diff($required, $found) !== []) {
            throw new AdmissionRejected('required_budget_missing');
        }

        return $rows;
    }

    /** @param list<stdClass> $rows
     * @return list<stdClass>
     */
    public function lockBudgets(array $rows, LockedTransactionContext $tx): array
    {
        $unique = [];
        foreach ($rows as $row) {
            $key = new BudgetKey(new TenantKey($row->tenant_key), $row->scope_key);
            $unique[$key->orderKey()] = $row;
        }
        ksort($unique, SORT_STRING);
        $result = [];
        foreach ($unique as $row) {
            $locked = $this->locks->rows(AuthorityLockTier::Budget, new TenantKey($row->tenant_key), [$row->scope_key], $tx)[0];
            if ($locked->id !== $row->id) {
                throw new AdmissionRejected('budget_identity_changed');
            }
            $result[] = $locked;
        }

        return $result;
    }

    public function byAttempt(TenantKey $tenant, Uuid $operation, Uuid $attempt): ?stdClass
    {
        return $this->scope->connection->table(PackageSchema::tables()['demands'])->where('tenant_key', $tenant->value)
            ->where('operation_id', $operation->value)->where('physical_attempt_id', $attempt->value)->first();
    }

    /** @param list<stdClass> $budgets */
    public function insert(TenantKey $tenant, WireRequest $request, Uuid $profile, string $purpose, DateTimeImmutable $deadline,
        DateTimeImmutable $expires, string $intent, array $budgets): stdClass
    {
        $db = $this->scope->connection;
        $t = PackageSchema::tables();
        $id = Uuid::random();
        $scopeKeys = array_map(static fn ($budget) => $budget->tenant_key.':'.$budget->id, $budgets);
        sort($scopeKeys, SORT_STRING);
        $db->table($t['demands'])->insert(['id' => $id->value, 'tenant_key' => $tenant->value, 'operation_id' => $request->operationId->value,
            'profile_id' => $profile->value, 'target_id' => $request->targetId->value, 'purpose' => $purpose, 'cost' => 1,
            'deadline' => $deadline->format('Y-m-d H:i:s.uP'), 'expires_at' => $expires->format('Y-m-d H:i:s.uP'), 'physical_attempt_id' => $request->physicalAttemptId->value,
            'wire_fingerprint' => $request->fingerprint()->hex, 'intent_hash' => $intent, 'scope_hash' => hash('sha256', implode(',', $scopeKeys))]);
        foreach ($budgets as $budget) {
            $db->table($t['demand_scopes'])->insert(['tenant_key' => $tenant->value, 'demand_id' => $id->value,
                'budget_tenant_key' => $budget->tenant_key, 'budget_id' => $budget->id]);
        }

        return $this->byAttempt($tenant, $request->operationId, $request->physicalAttemptId);
    }

    /** @param list<string> $budgetIds
     * @return list<stdClass>
     */
    public function predecessors(stdClass $candidate, array $budgetIds): array
    {
        $t = PackageSchema::tables();

        return $this->scope->connection->table($t['demands'].' as d')->join($t['operations'].' as o', 'o.id', '=', 'd.operation_id')
            ->where('d.state', 'active')->whereNotNull('d.physical_attempt_id')->whereRaw('(d.sequence, d.id) < (?, ?::uuid)', [$candidate->sequence, $candidate->id])
            ->where('o.status', 'active')->whereNull('o.revoked_at')->whereRaw('least(d.deadline, d.expires_at, o.deadline) > clock_timestamp()')
            ->whereExists(function ($q) use ($t, $budgetIds): void {
                $q->selectRaw('1')->from($t['demand_scopes'].' as s')->whereColumn('s.demand_id', 'd.id')->whereIn('s.budget_id', $budgetIds);
            })->select('d.*')->orderBy('d.sequence')->orderBy('d.id')->limit(1025)->get()->all();
    }

    /** @param list<string> $demandIds
     * @return array<string, list<stdClass>>
     */
    public function scopes(array $demandIds): array
    {
        $t = PackageSchema::tables();
        $result = [];
        $rows = $this->scope->connection->table($t['demand_scopes'].' as s')->join($t['budgets'].' as b', 'b.id', '=', 's.budget_id')
            ->whereIn('s.demand_id', $demandIds)->select(['b.*', 's.demand_id'])->get();
        foreach ($rows as $row) {
            $result[$row->demand_id][] = $row;
        }

        return $result;
    }

    public function ticket(stdClass $row, string $limitingScope): DemandTicket
    {
        return new DemandTicket(new Uuid($row->id), new Uuid($row->operation_id), new Uuid($row->physical_attempt_id), (int) $row->sequence,
            $limitingScope, new DateTimeImmutable($row->expires_at), new DateTimeImmutable($row->deadline), DemandState::from($row->state));
    }

    public function pendingForBudget(string $budgetId): object
    {
        $t = PackageSchema::tables();

        return $this->scope->connection->table($t['demands'].' as d')->join($t['demand_scopes'].' as s', 's.demand_id', '=', 'd.id')
            ->join($t['operations'].' as o', 'o.id', '=', 'd.operation_id')->where('s.budget_id', $budgetId)
            ->whereIn('d.state', ['active', 'admitted'])->where('o.status', 'active')->whereNull('o.revoked_at')
            ->whereRaw('least(d.deadline, d.expires_at, o.deadline) > clock_timestamp()')
            ->selectRaw('count(*) AS pending, min(least(d.deadline, d.expires_at, o.deadline)) AS retry_at')->first();
    }

    public function state(stdClass $row, string $state): void
    {
        $this->scope->connection->table(PackageSchema::tables()['demands'])->where('tenant_key', $row->tenant_key)->where('id', $row->id)->update(['state' => $state]);
        $row->state = $state;
    }

    /** @param list<stdClass> $budgets
     * @return array<string, DateTimeImmutable>
     */
    public function availability(array $budgets, int $retryMs): array
    {
        $t = PackageSchema::tables();
        $result = [];
        foreach ($budgets as $budget) {
            $value = $this->scope->connection->selectOne("SELECT ceil(extract(epoch FROM greatest(clock_timestamp(),
                b.tat - (b.emission_interval_us * (b.burst - 1)) * interval '1 microsecond',
                b.send_tat - (b.emission_interval_us * (b.burst - 1)) * interval '1 microsecond',
                CASE WHEN b.inflight >= b.max_inflight THEN greatest(clock_timestamp() + ? * interval '1 millisecond',
                    COALESCE((SELECT min(p.expires_at) FROM {$t['reservations']} r JOIN {$t['permits']} p ON p.id = r.permit_id
                        WHERE r.budget_id = b.id AND r.state IN ('reserved','spent') AND p.state IN ('reserved','authorized','unknown_after_authorization')),
                        clock_timestamp())) ELSE clock_timestamp() END,
                COALESCE((SELECT max(q.ends_at) FROM {$t['quota_windows']} q WHERE q.budget_id = b.id
                    AND q.starts_at <= clock_timestamp() AND q.ends_at > clock_timestamp() AND q.reserved + q.spent >= q.quota_limit), clock_timestamp())
                )) * 1000000)::bigint AS ready_us FROM {$t['budgets']} b WHERE b.id = ?", [$retryMs, $budget->id]);
            $result[$budget->id] = DatabaseClock::instant((int) $value->ready_us);
        }

        return $result;
    }
}
