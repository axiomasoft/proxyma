<?php

declare(strict_types=1);

namespace Proxyma\Application\Admission;

use DateTimeImmutable;
use LogicException;
use Proxyma\Application\Execution\WireSealer;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Contracts\LockedAdmissionGuard;
use Proxyma\Contracts\OwnerAuthority;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\AuthorityLockTier;
use Proxyma\Domain\Admission\DemandIntent;
use Proxyma\Domain\Admission\ReservationInput;
use Proxyma\Domain\Execution\AuthorizedWireRequest;
use Proxyma\Domain\Execution\AuthorizedWireSnapshot;
use Proxyma\Domain\Execution\ClaimCandidate;
use Proxyma\Domain\Execution\LockedOwnerState;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\PermitState;
use Proxyma\Domain\Execution\ScheduledPermit;
use Proxyma\Domain\Execution\SealedWire;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\HealthEpochMap;
use Proxyma\Domain\Panels\ExtensionKind;
use Proxyma\Infrastructure\Admission\ReservationCatalogLocks;
use Proxyma\Infrastructure\Database\BudgetRepository;
use Proxyma\Infrastructure\Database\DatabaseClock;
use Proxyma\Infrastructure\Database\DemandRepository;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Database\PermitRepository;
use Proxyma\Infrastructure\Database\QuotaRepository;
use WeakMap;

final class ClaimSend
{
    /** @var WeakMap<ClaimCandidate, array{SealedWire, AuthorizedWireSnapshot}> */
    private WeakMap $pending;

    /** @var WeakMap<SealedWire, array<string, int>> */
    private WeakMap $startBounds;

    private readonly QuotaRepository $quotas;

    public function __construct(private readonly TransactionCoordinator $transactions, private readonly AuthorityLocker $locks,
        private readonly OwnerAuthority $owners, private readonly ReservationCatalogLocks $catalog,
        private readonly DemandRepository $demands, private readonly BudgetRepository $budgets, private readonly PermitRepository $permits,
        private readonly DefinitionRegistry $definitions, private readonly WireSealer $sealer, private readonly array $guardIds = [],
        private readonly ?QuotaService $quotaService = null, ?QuotaRepository $quotaRepository = null)
    {
        $this->quotas = $quotaRepository ?? new QuotaRepository($transactions->scope);
        $this->pending = new WeakMap;
        $this->startBounds = new WeakMap;
    }

    /** Sample DB interval and monotonic clock before the caller's outer BEGIN. No locks or I/O adapters. */
    public function prepare(ScheduledPermit $permit, ReservationInput $input, SealedWire $wire): void
    {
        $this->transactions->scope->assertOutsideTransaction();
        $this->sealer->verify($wire);
        $sample = $this->transactions->nowMonotonicNs();
        $now = DatabaseClock::now($this->transactions->scope->connection);
        $this->quotaService?->assertWindow($permit->permitId->value, intdiv($input->request->timeouts->totalWithCleanupMarginNs(), 1000),
            $input->operation->owner->tenantKey, $input->operation->operationId);
        $expires = min($permit->expiresAt, $input->operation->deadlineAt, $input->request->deadlineAt, $input->ownerSnapshot->deadlineAt);
        $remaining = DatabaseClock::micros($expires) - DatabaseClock::micros($now)
            - intdiv($input->request->timeouts->totalWithCleanupMarginNs(), 1000);
        if ($remaining <= 0 || $remaining > intdiv(PHP_INT_MAX - $sample, 1000)) {
            throw new AdmissionRejected('claim_deadline');
        }
        $this->startBounds[$wire] = [...($this->startBounds[$wire] ?? []), $permit->permitId->value => $sample + $remaining * 1000];
    }

    public function claim(ScheduledPermit $permit, ReservationInput $input, SealedWire $wire): ClaimCandidate
    {
        $this->transactions->scope->assertOutsideTransaction();
        $this->prepare($permit, $input, $wire);

        return $this->transactions->run(fn ($tx) => $this->claimSendLocked($permit, $input, $wire, $tx));
    }

    /** Caller owns commit/rollback. Every denial throws to roll back any earlier finance mutation. */
    public function claimSendLocked(ScheduledPermit $scheduled, ReservationInput $input, SealedWire $wire,
        LockedTransactionContext $tx, ?LockedOwnerState $ownerState = null): ClaimCandidate
    {
        $scope = $this->transactions->scope;
        $scope->assertActive($tx);
        $latestStart = $this->startBounds[$wire][$scheduled->permitId->value] ?? throw new AdmissionRejected('claim_preparation_required');
        $this->sealer->verify($wire);
        if (! $input->workFits || $wire->request->fingerprint()->hex !== $input->request->fingerprint()->hex
            || $wire->route->id->value !== $input->route->id->value
            || ! $scheduled->ticket->operationId->equals($input->operation->operationId)
            || ! $scheduled->ticket->physicalAttemptId->equals($input->request->physicalAttemptId)) {
            throw new AdmissionRejected('claim_intent_conflict');
        }
        $owner = $input->operation->owner;
        $tenant = $owner->tenantKey;
        $ownerState ??= $this->locks->owner($this->owners, $owner, $input->ownerSnapshot, $tx);
        $this->locks->assertOwnerState($ownerState, $input->ownerSnapshot, $tx);
        if (! $ownerState->owner->matchesIdentityAndToken($owner) || $ownerState->purpose !== $input->operation->purpose
            || $ownerState->probeToken?->value !== $input->operation->probeToken?->value
            || $ownerState->adapterHealthId?->value !== $input->ownerSnapshot->adapterHealthId?->value
            || $ownerState->adapterHealthEpoch !== $input->ownerSnapshot->adapterHealthEpoch) {
            throw new AdmissionRejected('owner_snapshot_stale');
        }
        $root = $this->locks->operation($input->operation->operationId, $owner, $tx);
        if ($root->profile_id !== $input->profile->id->value || $root->target_id !== $input->target->id->value
            || $input->request->timeouts->totalTimeoutMs > (int) $root->request_timeout_ms
            || $input->request->timeouts->connectTimeoutMs > (int) $root->connect_timeout_ms) {
            throw new AdmissionRejected('operation_intent_conflict');
        }
        $evidence = $this->catalog->lock($input, $tx);
        if ($input->route->endpointId !== null) {
            $endpoint = $scope->connection->table(PackageSchema::tables()['endpoints'])->where('id', $input->route->endpointId->value)->first();
            if ($endpoint->credential_ref !== $wire->credential?->reference->reference
                || ($wire->credential !== null && (int) $endpoint->credential_version !== $wire->credential->reference->version)) {
                throw new AdmissionRejected('prepared_credential_conflict');
            }
        }
        foreach ($this->definitions->selectExtensions(ExtensionKind::LockedAdmissionGuard, $this->guardIds) as $definition) {
            if (! $definition->implementation instanceof LockedAdmissionGuard) {
                throw new AdmissionRejected('invalid_admission_guard');
            }
            $this->locks->guard($definition->id, $definition->implementation, $input->request, $ownerState, $tx);
        }
        $own = $this->demands->resolveBudgets($tenant, $input->request, $input->selection, $input->additionalScopes());
        $locked = $this->demands->lockBudgets($own, $tx);
        $this->quotas->lockWindows($locked, $scheduled->permitId->value);
        $demand = $this->locks->rows(AuthorityLockTier::Demand, $tenant, [$scheduled->ticket->demandId->value], $tx)[0];
        $permit = $this->locks->rows(AuthorityLockTier::Permit, $tenant, [$scheduled->permitId->value], $tx)[0];
        if ($permit->state !== PermitState::Reserved->value) {
            throw new AdmissionRejected($permit->authorized_at !== null ? 'already_claimed' : 'permit_terminal');
        }
        PermitState::Reserved->transitionTo(PermitState::Authorized);
        if ($permit->reservation_intent === null || $permit->demand_id !== $demand->id || $permit->operation_id !== $root->id
            || $permit->wire_fingerprint !== $wire->request->fingerprint()->hex || (int) $permit->cost !== 1
            || $permit->route_id !== $input->route->id->value || $permit->target_id !== $input->target->id->value
            || $demand->intent_hash !== DemandIntent::hash($input->request, $input->selection, $own) || $demand->traffic_class !== $input->request->trafficClass) {
            throw new AdmissionRejected('claim_fingerprint_conflict');
        }
        $stored = json_decode($permit->reservation_evidence, true, flags: JSON_THROW_ON_ERROR);
        $evidence['versions']['operation:'.$root->id] = (int) $root->revision;
        foreach ($locked as $budget) {
            $evidence['versions']['budget:'.$budget->id] = (int) $budget->revision;
        }
        ksort($stored['versions']);
        ksort($evidence['versions']);
        ksort($stored['epochs']);
        ksort($evidence['epochs']);
        if ($stored['versions'] !== $evidence['versions'] || $stored['epochs'] !== $evidence['epochs']) {
            throw new AdmissionRejected('claim_evidence_changed');
        }
        $references = $this->permits->references($permit->id);
        $expected = array_map(static fn ($row) => $row->tenant_key.':'.$row->id, $locked);
        $actual = array_map(static fn ($row) => $row->budget_tenant_key.':'.$row->budget_id, $references);
        sort($expected);
        sort($actual);
        if ($expected !== $actual || array_filter($references, static fn ($r) => $r->state !== 'reserved' || (int) $r->cost !== 1 || $r->quota_window_id !== null) !== []) {
            throw new AdmissionRejected('reservation_accounting_conflict');
        }
        $this->quotaService?->assertWindow($permit->id, intdiv($input->request->timeouts->totalWithCleanupMarginNs(), 1000), $tenant, $input->operation->operationId);
        $quotaPlan = $this->quotas->plan($locked, $input->constraints, new DateTimeImmutable($permit->not_before), $input->request->trafficClass, $input->request->url->origin->budgetScopeKey());
        $quotaRefs = $this->quotas->references($permit->id);
        $expectedQuotas = array_keys($quotaPlan);
        $actualQuotas = array_column($quotaRefs, 'window_id');
        sort($actualQuotas);
        if ($expectedQuotas !== $actualQuotas || count($quotaPlan) !== count($stored['quota'] ?? [])
            || array_filter($quotaRefs, static fn ($ref) => $ref->state !== 'reserved' || (int) $ref->cost !== 1) !== []) {
            throw new AdmissionRejected('quota_ledger_conflict');
        }
        if ($quotaPlan !== [] && $this->quotaService === null) {
            throw new AdmissionRejected('quota_service_required');
        }
        foreach ($quotaPlan as $id => $window) {
            if (($stored['quota'][$id]['limit'] ?? null) !== $window['limit']) {
                throw new AdmissionRejected('quota_configuration_changed');
            }
        }
        $snapshots = $this->budgets->read($locked, $input->constraints);
        $limits = json_decode($permit->reservation_limits, true, flags: JSON_THROW_ON_ERROR);
        foreach ($snapshots as $id => $snapshot) {
            if (($limits[$id]['interval'] ?? null) !== $snapshot['gcra']->intervalUs
                || ($limits[$id]['burst'] ?? null) !== $snapshot['gcra']->burst || ($limits[$id]['cap'] ?? null) !== $snapshot['cap']) {
                throw new AdmissionRejected('budget_configuration_changed');
            }
        }
        $this->budgets->planSend($snapshots, DatabaseClock::micros(DatabaseClock::now($scope->connection)));
        $safe = ['route_id' => $input->route->id->value, 'endpoint_id' => $input->route->endpointId?->value,
            'credential_version' => $input->selection->credentialVersion, 'fingerprint' => $input->request->fingerprint()->hex,
            'versions' => $evidence['versions'], 'epochs' => $evidence['epochs'], 'quota' => array_keys($quotaPlan), 'dispatch' => 'unknown'];
        try {
            $authorized = $this->permits->authorize($permit, $input, $ownerState, $snapshots, $safe, $quotaPlan);
        } catch (AdmissionRejected $rejected) {
            $this->quotaService?->assertWindow($permit->id, intdiv($input->request->timeouts->totalWithCleanupMarginNs(), 1000), $tenant, $input->operation->operationId);
            throw $rejected;
        }
        $at = new DateTimeImmutable($authorized->authorized_at);
        $this->quotas->consume($permit->id, $at);
        $this->budgets->applySend($this->budgets->planSend($snapshots, DatabaseClock::micros($at)));
        $candidate = $this->transactions->issueCandidate(new Uuid($permit->id), $input->request->operationId,
            $input->request->physicalAttemptId, $input->request->fingerprint(), $at, new DateTimeImmutable($authorized->expires_at), $tx);
        $snapshot = new AuthorizedWireSnapshot(new Uuid($permit->id), $input->route->id, $input->route->kind, $input->route->endpointId,
            $input->selection->credentialVersion, $wire->destination, $input->route->egress, $input->request->url->origin,
            $input->policy->outcomePolicyKey, new HealthEpochMap($evidence['epochs']), $owner, $input->request->fingerprint(),
            min($latestStart, $candidate->conservativeLatestStartNs($input->request->timeouts->totalWithCleanupMarginNs())));
        $this->pending[$candidate] = [$wire, $snapshot];
        $this->transactions->bindAuthorization($candidate, $snapshot, fn () => $this->recordNoDispatch($candidate), $wire, $this->sealer);

        return $candidate;
    }

    public function activate(ClaimCandidate $candidate): AuthorizedWireRequest
    {
        [$wire, $snapshot] = $this->pending[$candidate] ?? throw new LogicException('Unknown sealed claim candidate.');

        return $this->transactions->activate($candidate, $wire->request, $snapshot);
    }

    /** Late refusal is a durable fact, independent of a still-live owner. No refund or new claim. */
    private function recordNoDispatch(ClaimCandidate $candidate): void
    {
        $scope = $this->transactions->scope;
        $scope->assertOutsideTransaction();
        $db = $scope->connection;
        $t = PackageSchema::tables();
        $db->transaction(function () use ($db, $t, $candidate): void {
            $db->table($t['operations'])->where('id', $candidate->operationId->value)->lockForUpdate()->first();
            $permit = $db->table($t['permits'])->where('id', $candidate->permitId->value)->lockForUpdate()->first();
            if ($permit === null || $permit->state !== 'authorized') {
                return;
            }
            PermitState::Authorized->transitionTo(PermitState::UnknownAfterAuthorization);
            $db->table($t['permits'])->where('id', $permit->id)->update(['state' => 'unknown_after_authorization']);
            $db->table($t['attempts'])->where('permit_id', $permit->id)->where('status', 'authorized')->update([
                'status' => 'unknown_after_authorization', 'dispatch_refused_at' => $db->raw('clock_timestamp()'),
                'dispatch_outcome' => 'not_dispatched']);
        });
    }
}
