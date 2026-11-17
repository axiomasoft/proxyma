<?php

declare(strict_types=1);

namespace Proxyma\Application\Admission;

use DateTimeImmutable;
use Packman\Attributes\Api;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Contracts\Admission\OwnerAuthority;
use Proxyma\Contracts\Admission\WorkBudget;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\AuthorityLockTier;
use Proxyma\Domain\Admission\DemandIntent;
use Proxyma\Domain\Admission\DemandWait;
use Proxyma\Domain\Admission\FifoPolicy;
use Proxyma\Domain\Admission\QueueLimits;
use Proxyma\Domain\Execution\DemandPurpose;
use Proxyma\Domain\Execution\DemandTicket;
use Proxyma\Domain\Execution\Duration;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\OperationCorrelation;
use Proxyma\Domain\Execution\OwnerSnapshot;
use Proxyma\Domain\Execution\RetryAt;
use Proxyma\Domain\Execution\RouteSelection;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Admission\DatabaseOwnerAuthority;
use Proxyma\Infrastructure\Database\DatabaseClock;
use Proxyma\Infrastructure\Database\DemandRepository;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Internal\Database\RowValues;
use stdClass;

#[Api(since: '1.0')]
final readonly class DemandQueue
{
    public function __construct(private TransactionCoordinator $transactions, private AuthorityLocker $locks,
        private DemandRepository $repository, private OwnerAuthority $owners, private CatalogAccess $access,
        private QueueLimits $limits = new QueueLimits) {}

    /** @param list<string> $additionalScopes */
    public function enqueue(WireRequest $request, OperationCorrelation $operation, RouteSelection $route, WorkBudget $work, array $additionalScopes = []): DemandTicket|DemandWait
    {
        $snapshot = $this->prepare($request, $operation, 'execution.demand.enqueue');
        $fits = $work->canStart(new Duration($request->timeouts->totalTimeoutMs + $request->timeouts->cleanupMarginMs));

        return $this->transactions->run(function ($tx) use ($request, $operation, $route, $additionalScopes, $snapshot, $fits): DemandTicket|DemandWait {
            [$root, $deadline] = $this->lockOperation($request, $operation, $route, $snapshot, $tx);
            $tenant = $operation->owner->tenantKey;
            $budgets = $this->repository->resolveBudgets($tenant, $request, $route, $additionalScopes);
            $budgets = $this->repository->lockBudgets($budgets, $tx);
            $intent = $this->intent($request, $route, $budgets);
            $existing = $this->repository->byAttempt($tenant, $request->operationId, $request->physicalAttemptId);
            if ($existing !== null) {
                $this->assertIntent($existing, $intent);
                $row = $this->lockDemand($tenant, RowValues::colStr($existing, 'id'), $tx);
                $this->expire($row);

                return $this->repository->ticket($row, RowValues::colStr($budgets[0], 'scope_key'));
            }
            $now = DatabaseClock::now($this->transactions->scope->connection);
            if (! $fits) {
                return $this->wait('slice_exhausted', $now, $deadline);
            }
            $count = $this->transactions->scope->connection->table(PackageSchema::tables()['demands'])
                ->where('tenant_key', $tenant->value)->where('operation_id', RowValues::colStr($root, 'id'))->whereNotNull('physical_attempt_id')->count();
            if ($count >= RowValues::colInt($root, 'max_physical_attempts')) {
                throw new AdmissionRejected('physical_attempt_limit');
            }
            foreach ($budgets as $budget) {
                $pending = $this->repository->pendingForBudget(RowValues::colStr($budget, 'id'));
                if (RowValues::colInt($pending, 'pending') >= $this->limits->maxPendingPerScope) {
                    return new DemandWait('queue_full', new RetryAt(min($deadline, new DateTimeImmutable(RowValues::colStr($pending, 'retry_at')))));
                }
            }
            $expires = min($deadline, $now->modify('+'.$this->limits->ticketTtlMs.' milliseconds'));
            $row = $this->repository->insert($tenant, $request, new Uuid(RowValues::colStr($root, 'profile_id')), $operation->purpose === DemandPurpose::Probe ? 'probe' : 'normal',
                $deadline, $expires, $intent, $budgets);

            return $this->repository->ticket($row, RowValues::colStr($budgets[0], 'scope_key'));
        });
    }

    /** Queue handoff only: this does not authorize a physical send.
     * @param  list<string>  $additionalScopes
     */
    public function take(DemandTicket $ticket, WireRequest $request, OperationCorrelation $operation, RouteSelection $route, WorkBudget $work, array $additionalScopes = []): DemandTicket|DemandWait
    {
        $snapshot = $this->prepare($request, $operation, 'execution.demand.take');
        $fits = $work->canStart(new Duration($request->timeouts->totalTimeoutMs + $request->timeouts->cleanupMarginMs));

        return $this->transactions->run(function ($tx) use ($ticket, $request, $operation, $route, $additionalScopes, $snapshot, $fits): DemandTicket|DemandWait {
            [, $deadline] = $this->lockOperation($request, $operation, $route, $snapshot, $tx);
            $tenant = $operation->owner->tenantKey;
            $existing = $this->repository->byAttempt($tenant, $request->operationId, $request->physicalAttemptId);
            if ($existing === null || RowValues::colStr($existing, 'id') !== $ticket->demandId->value || ! $ticket->operationId->equals($request->operationId)
                || ! $ticket->physicalAttemptId->equals($request->physicalAttemptId)) {
                throw new AdmissionRejected('demand_identity_conflict');
            }
            $ownBudgets = $this->repository->resolveBudgets($tenant, $request, $route, $additionalScopes);
            $this->assertIntent($existing, $this->intent($request, $route, $ownBudgets));
            $ownScopeKey = RowValues::colStr($ownBudgets[0], 'scope_key');
            $ownBudgetIds = array_map(static fn (stdClass $b): string => RowValues::colStr($b, 'id'), $ownBudgets);
            // Сначала полные наборы всех старших конфликтующих tickets, затем единый sorted lock set.
            $older = $this->repository->predecessors($existing, $ownBudgetIds);
            if (count($older) > 1024) {
                throw new AdmissionRejected('conflict_scan_bound');
            }
            $sets = $this->repository->scopes(array_map(static fn (stdClass $o): string => RowValues::colStr($o, 'id'), $older));
            $allBudgets = $ownBudgets;
            foreach ($sets as $set) {
                $allBudgets = [...$allBudgets, ...$set];
            }
            $locked = $this->repository->lockBudgets($allBudgets, $tx);
            $row = $this->lockDemand($tenant, RowValues::colStr($existing, 'id'), $tx);
            $now = DatabaseClock::now($this->transactions->scope->connection);
            $this->expire($row, $now);
            if (RowValues::colStr($row, 'state') !== 'active') {
                return $this->repository->ticket($row, $ownScopeKey);
            }
            if (! $fits) {
                return $this->wait('slice_exhausted', $now, min($deadline, new DateTimeImmutable(RowValues::colStr($row, 'expires_at'))));
            }
            $availability = $this->repository->availability($locked, $this->limits->retryMs);
            // Все availability SQL должны завершиться раньше fresh decision clock.
            $now = DatabaseClock::now($this->transactions->scope->connection);
            $predecessors = [];
            foreach ($this->repository->predecessors($existing, $ownBudgetIds) as $olderRow) {
                $olderId = RowValues::colStr($olderRow, 'id');
                $set = $sets[$olderId] ?? throw new AdmissionRejected('conflict_set_changed');
                if ($set === []) {
                    throw new AdmissionRejected('required_budget_missing');
                }
                $olderReady = null;
                foreach ($set as $b) {
                    $bReady = $availability[RowValues::colStr($b, 'id')];
                    $olderReady = $olderReady === null ? $bReady : max($olderReady, $bReady);
                }
                $predecessors[] = [
                    'id' => $olderId,
                    'sequence' => RowValues::colInt($olderRow, 'sequence'),
                    'ready' => $olderReady,
                    'expires' => min(new DateTimeImmutable(RowValues::colStr($olderRow, 'expires_at')), new DateTimeImmutable(RowValues::colStr($olderRow, 'deadline'))),
                ];
            }
            $ready = null;
            foreach ($ownBudgetIds as $ownBudgetId) {
                $ownReady = $availability[$ownBudgetId];
                $ready = $ready === null ? $ownReady : max($ready, $ownReady);
            }
            $ready ??= $now;
            $wait = new FifoPolicy($this->limits)->wait($now, $ready, new DateTimeImmutable(RowValues::colStr($row, 'expires_at')), $predecessors);
            if ($wait !== null) {
                return $wait;
            }
            $db = $this->transactions->scope->connection;
            $updated = $db->table(PackageSchema::tables()['demands'])->where('id', RowValues::colStr($row, 'id'))->where('tenant_key', $tenant->value)
                ->where('state', 'active')->whereRaw('expires_at > clock_timestamp() AND deadline >= clock_timestamp() + ? * interval \'1 millisecond\'',
                    [$request->timeouts->totalTimeoutMs + $request->timeouts->cleanupMarginMs])->update(['state' => 'admitted', 'admitted_at' => $db->raw('clock_timestamp()')]);
            if ($updated !== 1) {
                $this->expire($row);

                return $this->repository->ticket($row, $ownScopeKey);
            }
            $row->state = 'admitted';

            return $this->repository->ticket($row, $ownScopeKey);
        });
    }

    public function cancel(TenantKey $tenant, Uuid $operation, Uuid $demand): void
    {
        $this->transactions->scope->assertOutsideTransaction();
        $this->access->authorize($tenant, 'execution.demand.cancel', $operation);
        $this->transactions->run(function ($tx) use ($tenant, $operation, $demand): void {
            $this->locks->rows(AuthorityLockTier::Operation, $tenant, [$operation->value], $tx);
            $exists = $this->transactions->scope->connection->table(PackageSchema::tables()['demands'])
                ->where('tenant_key', $tenant->value)->where('operation_id', $operation->value)->where('id', $demand->value)->exists();
            if (! $exists) {
                throw new AdmissionRejected('demand_identity_conflict');
            }
            $sets = $this->repository->scopes([$demand->value]);
            $this->repository->lockBudgets($sets[$demand->value] ?? [], $tx);
            $row = $this->lockDemand($tenant, $demand->value, $tx);
            if ($row->operation_id !== $operation->value) {
                throw new AdmissionRejected('demand_identity_conflict');
            }
            if (! in_array($row->state, ['active', 'admitted'], true)) {
                return;
            }
            if ($this->transactions->scope->connection->table(PackageSchema::tables()['permits'])->where('demand_id', $row->id)->exists()) {
                throw new AdmissionRejected('permit_accounting_cancellation_required');
            }
            $this->repository->state($row, 'cancelled');
        });
    }

    private function prepare(WireRequest $request, OperationCorrelation $operation, string $ability): OwnerSnapshot
    {
        $this->transactions->scope->assertOutsideTransaction();
        if (! $request->operationId->equals($operation->operationId)) {
            throw new AdmissionRejected('operation_identity_conflict');
        }
        $this->access->authorize($operation->owner->tenantKey, $ability, $operation->operationId);

        return $this->owners->snapshot($operation->owner) ?? throw new AdmissionRejected('owner_lost');
    }

    /** @return array{stdClass, DateTimeImmutable} */
    private function lockOperation(WireRequest $request, OperationCorrelation $operation, RouteSelection $route, OwnerSnapshot $snapshot, LockedTransactionContext $tx): array
    {
        $state = $this->locks->owner($this->owners, $operation->owner, $snapshot, $tx);
        $db = $this->transactions->scope->connection;
        if ($this->owners instanceof DatabaseOwnerAuthority) {
            if ($operation->owner->ownerId !== $request->operationId->value) {
                throw new AdmissionRejected('operation_owner_lost');
            }
            // Standalone owner уже держит этот root; не берём его повторно после prefix.
            $root = $db->table(PackageSchema::tables()['operations'])->where('tenant_key', $operation->owner->tenantKey->value)->where('id', $request->operationId->value)->first();
        } else {
            $root = $this->locks->operation($request->operationId, $operation->owner, $tx);
        }
        if ($root === null || $route->profileId === null || RowValues::colStr($root, 'profile_id') !== $route->profileId->value || RowValues::colStr($root, 'target_id') !== $request->targetId->value) {
            throw new AdmissionRejected('operation_route_target_mismatch');
        }
        $deadline = min(new DateTimeImmutable(RowValues::colStr($root, 'deadline')), $operation->deadlineAt, $state->deadlineAt, $request->deadlineAt);
        $now = DatabaseClock::now($db);
        if (DatabaseClock::micros($deadline) - DatabaseClock::micros($now) < $request->timeouts->totalWithCleanupMarginNs() / 1000) {
            throw new AdmissionRejected('operation_deadline');
        }

        return [$root, $deadline];
    }

    /** @param list<stdClass> $budgets */
    private function intent(WireRequest $request, RouteSelection $route, array $budgets): string
    {
        return DemandIntent::hash($request, $route, $budgets);
    }

    private function assertIntent(stdClass $row, string $intent): void
    {
        if ($row->intent_hash !== $intent) {
            throw new AdmissionRejected('physical_attempt_fingerprint_conflict');
        }
    }

    private function lockDemand(TenantKey $tenant, string $id, LockedTransactionContext $tx): stdClass
    {
        return $this->locks->rows(AuthorityLockTier::Demand, $tenant, [$id], $tx)[0];
    }

    private function expire(stdClass $row, ?DateTimeImmutable $now = null): void
    {
        $now ??= DatabaseClock::now($this->transactions->scope->connection);
        if (in_array(RowValues::colStr($row, 'state'), ['active', 'admitted'], true) && new DateTimeImmutable(RowValues::colStr($row, 'expires_at')) <= $now) {
            $this->repository->state($row, 'expired');
        }
    }

    private function wait(string $reason, DateTimeImmutable $now, DateTimeImmutable $deadline): DemandWait
    {
        return new DemandWait($reason, new RetryAt(min($deadline, $now->modify('+'.$this->limits->retryMs.' milliseconds'))));
    }
}
