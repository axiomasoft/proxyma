<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use Proxyma\Application\Admission\AuthorityLocker;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\AuthorityLockTier;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\PermitState;
use Proxyma\Domain\Policy\TenantKey;

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

    /** Compatible completion/recovery subset; versions may be retired, never revalidated as send authority. */
    public function lockSubset(object $root, LockedTransactionContext $tx): array
    {
        $db = $this->scope->connection;
        $t = PackageSchema::tables();
        $tenant = new TenantKey($root->tenant_key);
        $permits = $db->table($t['permits'])->where('operation_id', $root->id)->orderBy('id')->limit(257)->get()->all();
        $demands = $db->table($t['demands'])->where('operation_id', $root->id)->orderBy('id')->limit(257)->get()->all();
        if (count($permits) > 256 || count($demands) > 256) {
            throw new AdmissionRejected('recovery_operation_bound');
        }
        $ids = array_column($permits, 'id');
        $heads = [];
        $headKeys = [];
        $pinnedEndpoints = [];
        foreach ($permits as $permit) {
            $evidence = json_decode($permit->reservation_evidence, true, flags: JSON_THROW_ON_ERROR);
            foreach ($evidence['versions'] ?? [] as $key => $revision) {
                if (str_starts_with($key, 'endpoint:')) {
                    $pinnedEndpoints[] = substr($key, strlen('endpoint:'));
                }
                if (str_starts_with($key, 'head:')) {
                    $headKeys[] = $key;
                }
            }
        }
        foreach ($db->table($t['policy_heads'])->whereIn('tenant_key', array_unique([$tenant->value, TenantKey::SYSTEM]))
            ->whereIn($db->raw("('head:' || tenant_key || ':' || scope_key)"), array_unique($headKeys))->get() as $head) {
            $heads[$head->scope_key."\0".$head->tenant_key] = [$head->tenant_key, $head->scope_key];
        }
        ksort($heads, SORT_STRING);
        foreach ($heads as [$owner, $key]) {
            $this->locks->rows(AuthorityLockTier::Policy, new TenantKey($owner), [$key], $tx);
        }
        $this->locks->rows(AuthorityLockTier::Profile, $tenant, [$root->profile_id], $tx);
        $routes = $db->table($t['routes'])->whereIn('id', array_column($permits, 'route_id'))->get()->all();
        $endpoints = $db->table($t['endpoints'])->whereIn('id', array_unique([...$pinnedEndpoints, ...array_filter(array_column($routes, 'endpoint_id'))]))->get()->all();
        $tiers = [[AuthorityLockTier::Endpoint, $endpoints], [AuthorityLockTier::Route, $routes]];
        foreach (['route_health' => AuthorityLockTier::RouteHealth, 'route_target_health' => AuthorityLockTier::RouteTargetHealth] as $table => $tier) {
            $query = $db->table($t[$table])->whereIn('route_id', array_column($routes, 'id'));
            if ($table === 'route_target_health') {
                $query->where('target_id', $root->target_id);
            }
            $tiers[] = [$tier, $query->get()->all()];
        }
        $tiers[] = [AuthorityLockTier::Target, $db->table($t['targets'])->where('id', $root->target_id)->get()->all()];
        $tiers[] = [AuthorityLockTier::TargetHealth, $db->table($t['target_health'])->where('tenant_key', $tenant->value)->where('target_id', $root->target_id)->get()->all()];
        $epochs = [];
        foreach ($tiers as [$tier, $rows]) {
            usort($rows, static fn ($a, $b) => strcmp($a->id, $b->id));
            foreach ($rows as $row) {
                $locked = $this->locks->rows($tier, new TenantKey($row->tenant_key), [$row->id], $tx)[0];
                if (isset($locked->health_epoch)) {
                    $name = match ($tier) {
                        AuthorityLockTier::RouteHealth => 'route_health', AuthorityLockTier::RouteTargetHealth => 'route_target_health', default => 'target_health',
                    };
                    $epochs[$name.':'.$row->id] = (int) $locked->health_epoch;
                }
            }
        }
        $references = $db->table($t['reservations'])->whereIn('permit_id', $ids)->get()->all();
        $quotaRefs = $db->table($t['quota_reservations'])->whereIn('permit_id', $ids)->get()->all();
        $demandRefs = $db->table($t['demand_scopes'])->whereIn('demand_id', array_column($demands, 'id'))->get()->all();
        $budgetIds = array_unique([...array_column($references, 'budget_id'), ...array_column($quotaRefs, 'budget_id'), ...array_column($demandRefs, 'budget_id')]);
        $budgets = $this->demands->lockBudgets($db->table($t['budgets'])->whereIn('id', $budgetIds)->get()->all(), $tx);
        $this->quotas->lockWindows($budgets, $ids);
        $this->locks->rows(AuthorityLockTier::Demand, $tenant, array_column($demands, 'id'), $tx);
        $permits = $this->locks->rows(AuthorityLockTier::Permit, $tenant, $ids, $tx);
        $attempts = $db->table($t['attempts'])->whereIn('permit_id', $ids)->get()->all();
        $attempts = $this->locks->rows(AuthorityLockTier::Attempt, $tenant, array_column($attempts, 'id'), $tx);

        return compact('permits', 'demands', 'attempts', 'budgets', 'references', 'epochs');
    }

    public function release(object $permit, array $state): void
    {
        if ($permit->lease_released_at !== null) {
            return;
        }
        $this->quotas->release($permit->id);
        $this->budgets->release(array_values(array_filter($state['references'], static fn ($r) => $r->permit_id === $permit->id)), $state['budgets']);
        $this->scope->connection->table(PackageSchema::tables()['permits'])->where('id', $permit->id)->update(['lease_released_at' => $this->scope->connection->raw('clock_timestamp()')]);
    }

    public function expire(object $root, array $state): int
    {
        $db = $this->scope->connection;
        $t = PackageSchema::tables();
        $now = DatabaseClock::now($db);
        $dead = $root->status !== 'active' || $root->revoked_at !== null || new \DateTimeImmutable($root->deadline) <= $now;
        $changed = 0;
        foreach ($state['permits'] as $permit) {
            $status = PermitState::from($permit->state);
            if (($status === PermitState::Reserved && $dead) || new \DateTimeImmutable($permit->expires_at) <= $now) {
                if ($status === PermitState::Reserved) {
                    $status->transitionTo(PermitState::ExpiredBeforeSend);
                    $db->table($t['permits'])->where('id', $permit->id)->where('state', 'reserved')->update(['state' => 'expired_before_send']);
                    $db->table($t['demands'])->where('id', $permit->demand_id)->update(['state' => 'expired']);
                    $changed++;
                } elseif ($status === PermitState::Authorized) {
                    $status->transitionTo(PermitState::UnknownAfterAuthorization);
                    $db->table($t['permits'])->where('id', $permit->id)->where('state', 'authorized')->update(['state' => 'unknown_after_authorization']);
                    $db->table($t['attempts'])->where('permit_id', $permit->id)->whereNull('receipt_key')->update(['status' => 'unknown_after_authorization']);
                    $changed++;
                }
                if ($permit->lease_released_at === null) {
                    $this->release($permit, $state);
                    $changed++;
                }
            }
        }
        foreach ($state['demands'] as $demand) {
            if ($demand->state === 'active' && ($dead || min(new \DateTimeImmutable($demand->deadline), new \DateTimeImmutable($demand->expires_at)) <= $now)) {
                $changed += $db->table($t['demands'])->where('id', $demand->id)->where('state', 'active')->update(['state' => 'expired']);
            }
        }

        return $changed;
    }
}
