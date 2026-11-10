<?php

declare(strict_types=1);

namespace Proxyma\Application\Admission;

use DateTimeImmutable;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Contracts\BudgetScopeContributor;
use Proxyma\Contracts\CatalogQueries;
use Proxyma\Contracts\LockedAdmissionGuard;
use Proxyma\Contracts\OwnerAuthority;
use Proxyma\Contracts\WorkBudget;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\AuthorityLockTier;
use Proxyma\Domain\Admission\DemandIntent;
use Proxyma\Domain\Admission\DemandWait;
use Proxyma\Domain\Admission\FifoPolicy;
use Proxyma\Domain\Admission\QueueLimits;
use Proxyma\Domain\Admission\ReservationDeferred;
use Proxyma\Domain\Admission\ReservationInput;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Execution\DemandPurpose;
use Proxyma\Domain\Execution\DemandTicket;
use Proxyma\Domain\Execution\Duration;
use Proxyma\Domain\Execution\LockedOwnerState;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\OperationCorrelation;
use Proxyma\Domain\Execution\PermitState;
use Proxyma\Domain\Execution\RetryAt;
use Proxyma\Domain\Execution\RouteSelection;
use Proxyma\Domain\Execution\ScheduledPermit;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Panels\ExtensionKind;
use Proxyma\Domain\Policy\BudgetConstraint;
use Proxyma\Domain\Policy\BudgetConstraintSet;
use Proxyma\Domain\Policy\EffectivePolicy;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Admission\DatabaseOwnerAuthority;
use Proxyma\Infrastructure\Admission\PolicyAuthoritySnapshot;
use Proxyma\Infrastructure\Admission\ReservationCatalogLocks;
use Proxyma\Infrastructure\Database\BudgetRepository;
use Proxyma\Infrastructure\Database\DatabaseClock;
use Proxyma\Infrastructure\Database\DemandRepository;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Database\PermitRepository;
use Proxyma\Infrastructure\Database\QuotaRepository;

final readonly class ReservationService
{
    private QuotaRepository $quotas;

    /** @param list<string> $contributorIds
     * @param  list<string>  $guardIds
     */
    public function __construct(private TransactionCoordinator $transactions, private AuthorityLocker $locks, private CatalogAccess $access,
        private CatalogQueries $catalog, private OwnerAuthority $owners, private DefinitionRegistry $definitions, private DemandQueue $queue,
        private DemandRepository $demands, private BudgetRepository $budgets, private PermitRepository $permits, private ReservationCatalogLocks $catalogLocks,
        private QueueLimits $limits = new QueueLimits, private array $contributorIds = [], private array $guardIds = [], private int $maxInlineWaitUs = 1000000,
        ?QuotaRepository $quotaRepository = null, private TrafficSharePolicy $traffic = new TrafficSharePolicy)
    {
        $this->quotas = $quotaRepository ?? new QuotaRepository($transactions->scope, $traffic);
        if ($maxInlineWaitUs < 0 || $maxInlineWaitUs > 5000000) {
            throw new AdmissionRejected('invalid_reservation_wait_bound');
        }
    }

    public function prepare(WireRequest $request, OperationCorrelation $operation, RouteSelection $selection, EffectivePolicy $policy, WorkBudget $work): ReservationInput
    {
        $this->transactions->scope->assertOutsideTransaction();
        $tenant = $operation->owner->tenantKey;
        $this->access->authorize($tenant, 'execution.reservation.create', $operation->operationId);
        if (! $operation->operationId->equals($request->operationId) || ! $tenant->equals($policy->tenantKey)
            || ! $request->targetId->equals($policy->targetId) || $request->policyRevision !== $policy->effectiveRevision
            || $selection->profileId === null || $request->purpose !== $operation->purpose || $request->trafficClass !== $policy->trafficKey
            || $request->timeouts->totalTimeoutMs > $policy->timeouts->totalTimeoutMs
            || $request->timeouts->readTimeoutMs > $policy->timeouts->readTimeoutMs
            || $request->timeouts->connectTimeoutMs > $policy->timeouts->connectTimeoutMs) {
            throw new AdmissionRejected('reservation_identity_mismatch');
        }
        $route = $this->catalog->findRoute($tenant, $selection->routeId) ?? throw new AdmissionRejected('route_missing');
        $profile = $this->catalog->findProfile($tenant, $selection->profileId) ?? throw new AdmissionRejected('profile_missing');
        $target = $this->catalog->findTarget($tenant, $request->targetId) ?? throw new AdmissionRejected('target_missing');
        $this->access->authorize($tenant, 'catalog.route.select', $route->id);
        $this->access->authorize($tenant, 'catalog.profile.select', $profile->id);
        if (! $route->isAccessibleBy($tenant) || ! $route->enabled || $route->isRetired() || $policy->isRouteDenied($route)
            || ($route->kind === RouteKind::Direct && ! $policy->allowDirectRoute) || ! $policy->isProfileAllowed($profile->profileKey)
            || ! $policy->isDestinationAllowed($target->destinationPolicyKey) || ! $target->origin->equals($request->url->origin)
            || ! $route->egress->equals($selection->egress) || $route->credentialVersion !== $selection->credentialVersion
            || ($policy->routeProfileId !== null && ! $policy->routeProfileId->equals($profile->id))
            || ($policy->routeProfileRevision !== null && $policy->routeProfileRevision !== $profile->revision)) {
            throw new AdmissionRejected('reservation_policy_mismatch');
        }
        $extra = array_values($policy->budgets);
        $trafficScope = TrafficSharePolicy::scope($request->url->origin, $request->trafficClass);
        $persistedTraffic = $this->transactions->scope->connection->table(PackageSchema::tables()['budgets'])
            ->whereIn('tenant_key', array_unique([$tenant->value, TenantKey::SYSTEM]))->where('scope_key', $trafficScope)
            ->where('traffic_class', $request->trafficClass)->exists();
        if (array_key_exists($request->trafficClass, $this->traffic->classes) || $persistedTraffic) {
            $extra[] = new BudgetConstraint(TrafficSharePolicy::scope($request->url->origin, $request->trafficClass), 1, PHP_INT_MAX, PHP_INT_MAX, true);
        }
        foreach ($this->definitions->selectExtensions(ExtensionKind::BudgetScopeContributor, $this->contributorIds) as $definition) {
            $contributor = $definition->implementation;
            if (! $contributor instanceof BudgetScopeContributor) {
                throw new AdmissionRejected('invalid_budget_contributor');
            }
            $extra = [...$extra, ...$contributor->contribute($request, $route, $request->url->origin)];
        }
        $origin = new BudgetConstraint($request->url->origin->budgetScopeKey(), 1, PHP_INT_MAX, PHP_INT_MAX, true);
        if ($operation->purpose === DemandPurpose::Probe) {
            $extra[] = new BudgetConstraint($request->url->origin->probeScopeKey(), 1, PHP_INT_MAX, PHP_INT_MAX, true);
        }
        $set = BudgetConstraintSet::mergeMandatoryWithContributed([$origin], $extra);
        $snapshot = $this->owners->snapshot($operation->owner) ?? throw new AdmissionRejected('owner_lost');

        return new ReservationInput($request, $operation, $selection, $route, $profile, $target, $policy, $snapshot, $set->byScopeKey,
            $work->canStart(new Duration($request->timeouts->totalTimeoutMs + $request->timeouts->cleanupMarginMs)),
            PolicyAuthoritySnapshot::capture($this->transactions->scope->connection, $policy));
    }

    public function enqueue(ReservationInput $input, WorkBudget $work): DemandTicket|DemandWait
    {
        return $this->queue->enqueue($input->request, $input->operation, $input->selection, $work, $input->additionalScopes());
    }

    public function reserve(DemandTicket $ticket, ReservationInput $input, int $cost = 1): ScheduledPermit|DemandWait
    {
        $this->transactions->scope->assertOutsideTransaction();

        try {
            return $this->transactions->run(fn ($tx) => $this->reserveLocked($ticket, $input, $tx, cost: $cost));
        } catch (ReservationDeferred $deferred) {
            return $deferred->wait;
        }
    }

    public function reserveLocked(DemandTicket $ticket, ReservationInput $input, LockedTransactionContext $tx, ?LockedOwnerState $ownerState = null, int $cost = 1): ScheduledPermit
    {
        $scope = $this->transactions->scope;
        $scope->assertActive($tx);
        $owner = $input->operation->owner;
        $tenant = $owner->tenantKey;
        if ($cost !== 1 || ! $input->workFits || ! $ticket->operationId->equals($input->request->operationId)
            || ! $ticket->physicalAttemptId->equals($input->request->physicalAttemptId)) {
            throw new AdmissionRejected('reservation_cost_or_work_conflict');
        }
        $ownerState ??= $this->locks->owner($this->owners, $owner, $input->ownerSnapshot, $tx);
        $this->locks->assertOwnerState($ownerState, $input->ownerSnapshot, $tx);
        if (! $ownerState->owner->matchesIdentityAndToken($owner)) {
            throw new AdmissionRejected('owner_lost');
        }
        if ($this->owners instanceof DatabaseOwnerAuthority) {
            if ($owner->ownerId !== $ticket->operationId->value) {
                throw new AdmissionRejected('operation_owner_lost');
            }
        }
        // Даже supplied owner state не заменяет live package root mutex. Повторный lock здесь до budgets.
        $root = $this->locks->operation($ticket->operationId, $owner, $tx);
        if ($root->profile_id !== $input->profile->id->value || $root->target_id !== $input->target->id->value
            || $input->request->timeouts->totalTimeoutMs > (int) $root->request_timeout_ms
            || $input->request->timeouts->connectTimeoutMs > (int) $root->connect_timeout_ms) {
            throw new AdmissionRejected('operation_intent_conflict');
        }
        $evidence = $this->catalogLocks->lock($input, $tx);
        foreach ($this->definitions->selectExtensions(ExtensionKind::LockedAdmissionGuard, $this->guardIds) as $definition) {
            if (! $definition->implementation instanceof LockedAdmissionGuard) {
                throw new AdmissionRejected('invalid_admission_guard');
            }
            $this->locks->guard($definition->id, $definition->implementation, $input->request, $ownerState, $tx);
        }
        $own = $this->demands->resolveBudgets($tenant, $input->request, $input->selection, $input->additionalScopes());
        $demand = $this->demands->byAttempt($tenant, $ticket->operationId, $ticket->physicalAttemptId);
        if ($demand === null || $demand->id !== $ticket->demandId->value || $demand->wire_fingerprint !== $input->request->fingerprint()->hex
            || $demand->intent_hash !== DemandIntent::hash($input->request, $input->selection, $own)) {
            throw new AdmissionRejected('demand_fingerprint_conflict');
        }
        $sets = $this->demands->scopes([$demand->id]);
        if ($this->ids($own) !== $this->ids($sets[$demand->id] ?? [])) {
            throw new AdmissionRejected('demand_scope_conflict');
        }
        $older = $this->demands->predecessors($demand, array_column($own, 'id'));
        if (count($older) > 1024) {
            throw new AdmissionRejected('conflict_scan_bound');
        }
        $priorSets = $this->demands->scopes(array_column($older, 'id'));
        $all = $own;
        foreach ($priorSets as $set) {
            $all = [...$all, ...$set];
        }
        $locked = $this->demands->lockBudgets($all, $tx);
        $ownIds = array_flip(array_column($own, 'id'));
        $this->quotas->lockWindows(array_values(array_filter($locked, static fn ($row) => isset($ownIds[$row->id]))));
        $demand = $this->locks->rows(AuthorityLockTier::Demand, $tenant, [$demand->id], $tx)[0];
        $intent = hash('sha256', json_encode([$demand->intent_hash, $input->request->fingerprint()->hex, $input->selection->routeId->value,
            $cost, $this->ids($own), $input->policy->policyHash->hex, $input->constraints,
            $input->route->revision, $input->route->endpointRevision, $input->profile->revision, $input->target->revision], JSON_THROW_ON_ERROR));
        $existing = $this->permits->existing($tenant, $demand->id, $tx);
        if ($existing !== null) {
            if ($existing->reservation_intent !== $intent) {
                throw new AdmissionRejected('reservation_fingerprint_conflict');
            }

            return $this->permits->result($existing, $this->demands->ticket($demand, $own[0]->scope_key));
        }
        if (! in_array($demand->state, ['active', 'admitted'], true)) {
            throw new AdmissionRejected('demand_terminal');
        }
        $snapshots = $this->budgets->read($locked, $input->constraints);
        $now = DatabaseClock::now($scope->connection);
        $nowUs = DatabaseClock::micros($now);
        $availability = $this->budgets->availability($snapshots, $nowUs);
        $ownSnapshots = array_intersect_key($snapshots, array_flip(array_column($own, 'id')));
        $quotaPlan = $this->quotas->plan(array_column($ownSnapshots, 'row'), $input->constraints, $now, $input->request->trafficClass, $input->request->url->origin->budgetScopeKey());
        $this->quotas->assertAvailable($quotaPlan);
        foreach ($this->quotas->availability($locked, $now) as $id => $ready) {
            $availability[$id] = max($availability[$id], $ready);
        }
        $slotUs = max(array_intersect_key($availability, $ownSnapshots));
        foreach ($ownSnapshots as $snapshot) {
            if ((int) $snapshot['row']->inflight >= $snapshot['cap']) {
                throw new ReservationDeferred(new DemandWait('capacity_wait', new RetryAt(DatabaseClock::instant($slotUs))));
            }
        }
        if ($slotUs - $nowUs > $this->maxInlineWaitUs) {
            throw new ReservationDeferred(new DemandWait('rate_wait', new RetryAt(DatabaseClock::instant($slotUs))));
        }
        $predecessors = [];
        foreach ($this->demands->predecessors($demand, array_column($own, 'id')) as $prior) {
            $set = $priorSets[$prior->id] ?? throw new AdmissionRejected('conflict_set_changed');
            $ready = max(array_map(fn ($row) => $availability[$row->id], $set));
            foreach ($set as $row) {
                if ((int) $snapshots[$row->id]['row']->inflight >= $snapshots[$row->id]['cap']) {
                    $ready = max($ready, $nowUs + $this->maxInlineWaitUs + 1);
                }
            }
            $predecessors[] = ['id' => $prior->id, 'sequence' => (int) $prior->sequence,
                'ready' => $ready <= $nowUs + $this->maxInlineWaitUs ? $now : DatabaseClock::instant($ready),
                'expires' => new DateTimeImmutable($prior->expires_at)];
        }
        $expires = min(new DateTimeImmutable($demand->expires_at), new DateTimeImmutable($demand->deadline),
            new DateTimeImmutable($root->deadline), $input->request->deadlineAt, $ownerState->deadlineAt);
        $wait = (new FifoPolicy($this->limits))->wait($now, $now, $expires, $predecessors);
        if ($wait !== null) {
            throw new ReservationDeferred($wait);
        }
        $requiredUs = intdiv($input->request->timeouts->totalWithCleanupMarginNs(), 1000);
        if ($slotUs > DatabaseClock::micros($expires) - $requiredUs) {
            throw new AdmissionRejected('reservation_deadline');
        }
        foreach ($quotaPlan as $window) {
            $expires = min($expires, new DateTimeImmutable($window['ends']));
        }
        if ($slotUs + $requiredUs >= DatabaseClock::micros($expires)) {
            throw new ReservationDeferred(new DemandWait('quota_window_wait', new RetryAt($expires)));
        }
        $evidence['quota'] = array_map(static function ($row) {
            unset($row['reserved'], $row['spent']);

            return $row;
        }, $quotaPlan);
        $plan = $this->budgets->planReserve($ownSnapshots, $slotUs, $cost);
        $evidence['versions']['operation:'.$root->id] = (int) $root->revision;
        $effective = [];
        foreach ($ownSnapshots as $id => $snapshot) {
            $evidence['versions']['budget:'.$id] = (int) $snapshot['row']->revision;
            $effective[$id] = ['interval' => $snapshot['gcra']->intervalUs, 'burst' => $snapshot['gcra']->burst, 'cap' => $snapshot['cap']];
        }
        // Последний SQL guard — после всех возможных ожиданий locks, до первого изменения accounting.
        $clock = $scope->connection->selectOne('SELECT clock_timestamp() AS now');
        if (DatabaseClock::micros(new DateTimeImmutable($clock->now)) + $requiredUs > DatabaseClock::micros($expires)) {
            throw new AdmissionRejected('reservation_deadline');
        }
        $this->budgets->applyReserve($ownSnapshots, $plan);
        $permit = $this->permits->insert(['tenant_key' => $tenant->value, 'operation_id' => $root->id, 'demand_id' => $demand->id,
            'route_id' => $input->route->id->value, 'target_id' => $input->target->id->value, 'physical_attempt_id' => $ticket->physicalAttemptId->value,
            'wire_fingerprint' => $input->request->fingerprint()->hex, 'cost' => $cost, 'not_before' => DatabaseClock::instant($slotUs)->format('Y-m-d H:i:s.uP'),
            'expires_at' => $expires->format('Y-m-d H:i:s.uP'), 'reservation_intent' => $intent,
            'reservation_evidence' => json_encode($evidence, JSON_THROW_ON_ERROR), 'reservation_limits' => json_encode($effective, JSON_THROW_ON_ERROR)], $own);
        $this->quotas->reserve($permit, $quotaPlan);
        if ($demand->state === 'active') {
            $this->demands->state($demand, 'admitted');
        }

        return $this->permits->result($permit, $this->demands->ticket($demand, $own[0]->scope_key));
    }

    public function cancel(TenantKey $tenant, Uuid $operationId, Uuid $permitId): void
    {
        $scope = $this->transactions->scope;
        $scope->assertOutsideTransaction();
        $this->access->authorize($tenant, 'execution.reservation.cancel', $operationId);
        $this->transactions->run(function ($tx) use ($tenant, $operationId, $permitId, $scope): void {
            $this->locks->rows(AuthorityLockTier::Operation, $tenant, [$operationId->value], $tx);
            $db = $scope->connection;
            $t = PackageSchema::tables();
            $row = $db->table($t['permits'])->where('tenant_key', $tenant->value)->where('operation_id', $operationId->value)->where('id', $permitId->value)->first()
                ?? throw new AdmissionRejected('permit_missing');
            $references = $this->permits->references($row->id);
            $quotaReferences = $this->quotas->references($row->id);
            $budgetIds = array_unique([...array_column($references, 'budget_id'), ...array_column($quotaReferences, 'budget_id')]);
            $budgets = $db->table($t['budgets'])->whereIn('id', $budgetIds)->get()->all();
            $locked = $this->demands->lockBudgets($budgets, $tx);
            $this->quotas->lockWindows($locked, $row->id);
            $demand = $this->locks->rows(AuthorityLockTier::Demand, $tenant, [$row->demand_id], $tx)[0];
            $row = $this->locks->rows(AuthorityLockTier::Permit, $tenant, [$row->id], $tx)[0];
            $state = PermitState::from($row->state);
            if ($state === PermitState::CancelledBeforeSend || $state === PermitState::ExpiredBeforeSend) {
                return;
            }
            $next = $state->transitionTo(PermitState::CancelledBeforeSend);
            $this->quotas->release($row->id);
            $this->budgets->release($references, $locked);
            $db->table($t['permits'])->where('id', $row->id)->update(['state' => $next->value]);
            $this->demands->state($demand, 'cancelled');
        });
    }

    private function ids(array $rows): array
    {
        $ids = array_column($rows, 'id');
        sort($ids, SORT_STRING);

        return $ids;
    }
}
