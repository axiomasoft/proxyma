<?php

declare(strict_types=1);

namespace Proxyma\Application\Admission;

use DateTimeImmutable;
use Packman\Attributes\Api;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\AuthorityLockTier;
use Proxyma\Domain\Admission\NeedsReplan;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Execution\PermitState;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Audit\CatalogAudit;
use Proxyma\Infrastructure\Database\BudgetRepository;
use Proxyma\Infrastructure\Database\DatabaseClock;
use Proxyma\Infrastructure\Database\DemandRepository;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Database\QuotaRepository;
use Proxyma\Internal\Database\RowValues;
use stdClass;

#[Api(since: '1.0')]
final readonly class QuotaService
{
    public function __construct(private TransactionCoordinator $transactions, private AuthorityLocker $locks,
        private CatalogAccess $access, private QuotaRepository $repository, private DemandRepository $demands,
        private BudgetRepository $budgets, private TrafficSharePolicy $traffic) {}

    public function configure(TenantKey $tenant, Uuid $budgetId, ?int $hour, ?int $day, int $expectedRevision, ?CatalogMutationActor $actor = null): void
    {
        if (($hour !== null && $hour < 0) || ($day !== null && $day < 0)) {
            throw new AdmissionRejected('invalid_quota_limit');
        }
        $scope = $this->transactions->scope;
        $scope->assertOutsideTransaction();
        $context = $this->access->authorize($tenant, 'catalog.budget.configure', $budgetId, $actor?->actor);
        $hash = hash('sha256', json_encode(['quota.configure', $tenant->value, $budgetId->value, $hour, $day, $expectedRevision], JSON_THROW_ON_ERROR));
        $actor ??= new CatalogMutationActor($context->actor, 'configure fixed UTC quotas', $expectedRevision, $hash);
        if ($actor->expectedRevision !== $expectedRevision || $actor->idempotencyKey === null) {
            throw new AdmissionRejected('invalid_quota_actor');
        }
        $hash = hash('sha256', $hash.json_encode($actor, JSON_THROW_ON_ERROR));
        $this->transactions->run(function ($tx) use ($tenant, $budgetId, $hour, $day, $expectedRevision, $scope, $actor, $hash): void {
            $t = PackageSchema::tables($this->transactions->scope->connection);
            $row = $scope->connection->table($t['budgets'])->where('tenant_key', $tenant->value)->where('id', $budgetId->value)->first()
                ?? throw new AdmissionRejected('budget_missing');
            $row = $this->locks->rows(AuthorityLockTier::Budget, $tenant, [RowValues::colStr($row, 'scope_key')], $tx)[0];
            if ($this->receipt($tenant, $actor, $hash) !== null) {
                return;
            }
            if (RowValues::colInt($row, 'revision') !== $expectedRevision || RowValues::colNullableStr($row, 'traffic_parent_id') !== null) {
                throw new AdmissionRejected('budget_revision_changed');
            }
            $previous = clone $row;
            $scope->connection->table($t['budgets'])->where('id', RowValues::colStr($row, 'id'))
                ->update(['hour_limit' => $hour, 'day_limit' => $day, 'revision' => $expectedRevision + 1, 'updated_at' => $scope->connection->raw('clock_timestamp()')]);
            $row->hour_limit = $hour;
            $row->day_limit = $day;
            $row->revision = $expectedRevision + 1;
            QuotaRepository::provisionWindows($scope->connection, $row, DatabaseClock::now($scope->connection), reconcileHistory: true);
            new CatalogAudit($scope->connection)->mutation('budgets', $tenant, $budgetId, 'catalog.budget.configure', $actor, $hash, $previous, $row, ['hour_limit', 'day_limit']);
        });
    }

    /** @param list<Uuid> $budgetIds */
    public function provision(TenantKey $tenant, array $budgetIds): void
    {
        $scope = $this->transactions->scope;
        $scope->assertOutsideTransaction();
        foreach ($budgetIds as $id) {
            $this->access->authorize($tenant, 'catalog.budget.configure', $id);
        }
        $this->transactions->run(function ($tx) use ($scope, $tenant, $budgetIds): void {
            $budgetIdValues = array_map(static fn (Uuid $id): string => $id->value, $budgetIds);
            $rows = array_values(PackageSchema::query($scope->connection, 'budgets')->where('tenant_key', $tenant->value)
                ->whereIn('id', $budgetIdValues)->get()->all());
            if (count($rows) !== count(array_unique($budgetIdValues))) {
                throw new AdmissionRejected('budget_missing');
            }
            foreach ($this->demands->lockBudgets($rows, $tx) as $row) {
                QuotaRepository::provisionWindows($scope->connection, $row, DatabaseClock::now($scope->connection));
            }
        });
    }

    public function configureTrafficShare(TenantKey $tenant, Uuid $originBudget, CanonicalOrigin $origin, string $class, int $expectedRevision, ?CatalogMutationActor $actor = null): Uuid
    {
        $scope = $this->transactions->scope;
        $scope->assertOutsideTransaction();
        $context = $this->access->authorize($tenant, 'catalog.budget.configure', $originBudget, $actor?->actor);
        $percent = $this->traffic->classes[$class] ?? throw new AdmissionRejected('traffic_class_not_configured');
        $hash = hash('sha256', json_encode(['traffic.share', $tenant->value, $originBudget->value, $class, $percent, $expectedRevision], JSON_THROW_ON_ERROR));
        $actor ??= new CatalogMutationActor($context->actor, 'configure traffic capacity share', $expectedRevision, $hash);
        if ($actor->expectedRevision !== $expectedRevision || $actor->idempotencyKey === null) {
            throw new AdmissionRejected('invalid_quota_actor');
        }
        $hash = hash('sha256', $hash.json_encode($actor, JSON_THROW_ON_ERROR));

        return $this->transactions->run(function ($tx) use ($scope, $tenant, $originBudget, $origin, $class, $percent, $expectedRevision, $actor, $hash): Uuid {
            $t = PackageSchema::tables($this->transactions->scope->connection);
            $parent = $this->locks->rows(AuthorityLockTier::Budget, $tenant, [$origin->budgetScopeKey()], $tx)[0];
            $receipt = $this->receipt($tenant, $actor, $hash);
            if ($receipt !== null) {
                return new Uuid(RowValues::colStr($receipt, 'id'));
            }
            $parentId = RowValues::colStr($parent, 'id');
            if ($parentId !== $originBudget->value || RowValues::colInt($parent, 'revision') !== $expectedRevision || RowValues::colNullableStr($parent, 'traffic_parent_id') !== null) {
                throw new AdmissionRejected('traffic_parent_conflict');
            }
            $key = TrafficSharePolicy::scope($origin, $class);
            $this->locks->enter(AuthorityLockTier::Budget, $key."\0".$tenant->value, $tx);
            $row = $scope->connection->table($t['budgets'])->where('tenant_key', $tenant->value)->where('scope_key', $key)->lockForUpdate()->first();
            $previous = $row === null ? null : clone $row;
            $limit = TrafficSharePolicy::hourlyLimit(RowValues::colInt($parent, 'emission_interval_us'), $percent);
            if ($row === null) {
                $id = Uuid::random();
                $scope->connection->table($t['budgets'])->insert(['id' => $id->value, 'tenant_key' => $tenant->value, 'scope_key' => $key,
                    'emission_interval_us' => 1, 'burst' => 1, 'max_inflight' => PHP_INT_MAX, 'mandatory' => true,
                    'traffic_class' => $class, 'traffic_parent_id' => $parentId, 'traffic_percent' => $percent, 'hour_limit' => $limit]);
                $row = $scope->connection->table($t['budgets'])->where('id', $id->value)->first() ?? throw new AdmissionRejected('budget_missing');
            } else {
                if (RowValues::colNullableStr($row, 'traffic_parent_id') !== $parentId || RowValues::colNullableStr($row, 'traffic_class') !== $class) {
                    throw new AdmissionRejected('traffic_parent_conflict');
                }
                $nextRevision = RowValues::colInt($row, 'revision') + 1;
                $scope->connection->table($t['budgets'])->where('id', RowValues::colStr($row, 'id'))
                    ->update(['hour_limit' => $limit, 'traffic_percent' => $percent, 'revision' => $nextRevision, 'updated_at' => $scope->connection->raw('clock_timestamp()')]);
                $row->hour_limit = $limit;
                $row->traffic_percent = $percent;
                $row->revision = $nextRevision;
            }
            $rowId = RowValues::colStr($row, 'id');
            QuotaRepository::provisionWindows($scope->connection, $row, DatabaseClock::now($scope->connection), reconcileHistory: true);
            $childActor = new CatalogMutationActor($actor->actor, $actor->reason, $previous === null ? 0 : RowValues::colInt($previous, 'revision'), $actor->idempotencyKey);
            new CatalogAudit($scope->connection)->mutation('budgets', $tenant, new Uuid($rowId), 'catalog.budget.traffic-share', $childActor, $hash, $previous, $row, ['hour_limit', 'traffic_percent', 'traffic_class', 'traffic_parent_id']);

            return new Uuid($rowId);
        });
    }

    private function receipt(TenantKey $tenant, CatalogMutationActor $actor, string $hash): ?object
    {
        $receipt = PackageSchema::query($this->transactions->scope->connection, 'catalog_command_receipts')
            ->where('tenant_key', $tenant->value)->where('idempotency_key', CatalogAudit::key((string) $actor->idempotencyKey))->first();
        if ($receipt === null) {
            return null;
        }
        if (! hash_equals(RowValues::colStr($receipt, 'command_hash'), $hash)) {
            throw new AdmissionRejected('quota_idempotency_conflict');
        }

        return RowValues::object(json_decode(RowValues::colStr($receipt, 'result'), false, flags: JSON_THROW_ON_ERROR));
    }

    public function assertWindow(string $permitId, int $requiredUs, TenantKey $tenant, Uuid $operation): void
    {
        $permit = PackageSchema::query($this->transactions->scope->connection, 'permits')->where('id', $permitId)
            ->where('tenant_key', $tenant->value)->where('operation_id', $operation->value)->first() ?? throw new AdmissionRejected('permit_missing');
        if ($permit->state !== 'reserved') {
            return;
        }
        $refs = $this->repository->references($permitId);
        $now = DatabaseClock::micros(DatabaseClock::now($this->transactions->scope->connection));
        foreach ($refs as $ref) {
            if (RowValues::colStr($ref, 'state') === 'reserved' && $now + $requiredUs >= DatabaseClock::micros(new DateTimeImmutable(RowValues::colStr($ref, 'ends_at')))) {
                $this->replan($permitId);
            }
        }
    }

    private function replan(string $permitId): never
    {
        $scope = $this->transactions->scope;
        $row = PackageSchema::query($scope->connection, 'permits')->where('id', $permitId)->first()
            ?? throw new AdmissionRejected('permit_missing');
        $exception = new NeedsReplan(new TenantKey(RowValues::colStr($row, 'tenant_key')), new Uuid(RowValues::colStr($row, 'operation_id')), new Uuid($permitId));
        $cleanup = function () use ($exception): void {
            $exception->released = $this->releaseForReplan($exception);
        };
        if ($scope->connection->transactionLevel() > 0) {
            $this->transactions->afterRootRollback($cleanup);
        } else {
            $cleanup();
        }
        throw $exception;
    }

    /** Idempotent, after outer rollback; consumed quota is never released. */
    public function releaseForReplan(NeedsReplan $replan): bool
    {
        $scope = $this->transactions->scope;
        $scope->assertOutsideTransaction();
        $this->access->authorize($replan->tenant, 'execution.reservation.cancel', $replan->operationId);

        return $this->transactions->run(function ($tx) use ($scope, $replan): bool {
            $t = PackageSchema::tables($this->transactions->scope->connection);
            $db = $scope->connection;
            $this->locks->rows(AuthorityLockTier::Operation, $replan->tenant, [$replan->operationId->value], $tx);
            $row = $db->table($t['permits'])->where('tenant_key', $replan->tenant->value)->where('operation_id', $replan->operationId->value)
                ->where('id', $replan->permitId->value)->first() ?? throw new AdmissionRejected('permit_missing');
            $permitId = RowValues::colStr($row, 'id');
            $references = array_values($db->table($t['reservations'])->where('permit_id', $permitId)->get()->all());
            $quotaReferences = $this->repository->references($permitId);
            $budgetIds = array_values(array_unique([
                ...array_map(static fn (stdClass $r): string => RowValues::colStr($r, 'budget_id'), $references),
                ...array_map(static fn (stdClass $r): string => RowValues::colStr($r, 'budget_id'), $quotaReferences),
            ]));
            $budgets = array_values($db->table($t['budgets'])->whereIn('id', $budgetIds)->get()->all());
            $locked = $this->demands->lockBudgets($budgets, $tx);
            $this->repository->lockWindows($locked, $permitId);
            $this->locks->rows(AuthorityLockTier::Demand, $replan->tenant, [RowValues::colStr($row, 'demand_id')], $tx);
            $row = $this->locks->rows(AuthorityLockTier::Permit, $replan->tenant, [$permitId], $tx)[0];
            $lockedPermitId = RowValues::colStr($row, 'id');
            $lockedState = RowValues::colStr($row, 'state');
            if ($lockedState === 'expired_before_send' || $lockedState === 'cancelled_before_send') {
                return true;
            }
            if ($lockedState !== 'reserved') {
                return false;
            }
            PermitState::Reserved->transitionTo(PermitState::ExpiredBeforeSend);
            $this->repository->release($lockedPermitId);
            $this->budgets->release($references, $locked);
            $db->table($t['permits'])->where('id', $lockedPermitId)->update(['state' => 'expired_before_send']);
            $db->table($t['demands'])->where('id', RowValues::colStr($row, 'demand_id'))->update(['state' => 'expired']);

            return true;
        });
    }
}
