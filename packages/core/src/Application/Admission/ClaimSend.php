<?php

declare(strict_types=1);

namespace Proxyma\Application\Admission;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Packman\Attributes\Api;
use Proxyma\Application\Execution\OutcomePolicyRegistry;
use Proxyma\Application\Execution\WireSealer;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Application\Extensions\ExtensionRuntime;
use Proxyma\Contracts\Admission\LockedAdmissionGuard;
use Proxyma\Contracts\Admission\OwnerAuthority;
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
use Proxyma\Infrastructure\Health\ProbeClaims;
use Proxyma\Internal\Database\RowValues;
use stdClass;
use WeakMap;

#[Api(since: '1.0')]
final class ClaimSend
{
    /** @var WeakMap<ClaimCandidate, array{SealedWire, AuthorizedWireSnapshot}> */
    private WeakMap $pending;

    /** @var WeakMap<SealedWire, array<string, int>> */
    private WeakMap $startBounds;

    private readonly QuotaRepository $quotas;

    /**
     * @param  list<string>  $guardIds
     * @param  list<string>  $cooldownScopes
     * @param  list<string>  $contributorIds
     */
    public function __construct(private readonly TransactionCoordinator $transactions, private readonly AuthorityLocker $locks,
        private readonly OwnerAuthority $owners, private readonly ReservationCatalogLocks $catalog,
        private readonly DemandRepository $demands, private readonly BudgetRepository $budgets, private readonly PermitRepository $permits,
        private readonly DefinitionRegistry $definitions, private readonly WireSealer $sealer, private readonly array $guardIds = [],
        private readonly ?QuotaService $quotaService = null, ?QuotaRepository $quotaRepository = null,
        private readonly array $cooldownScopes = [], private readonly ?OutcomePolicyRegistry $outcomes = null,
        private readonly array $contributorIds = [])
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
        if ($input->operation->probeToken !== null) {
            $probe = new ProbeClaims($this->transactions->scope)->find($input->operation->probeToken->value)
                ?? throw new AdmissionRejected('probe_ownership_lost');
            $expires = min($expires, new DateTimeImmutable(RowValues::colStr($probe, 'expires_at')));
        }
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

        return $this->transactions->run(fn ($tx): ClaimCandidate => $this->claimSendLocked($permit, $input, $wire, $tx));
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
        $rootId = RowValues::colStr($root, 'id');
        if (RowValues::colStr($root, 'profile_id') !== $input->profile->id->value
            || RowValues::colStr($root, 'target_id') !== $input->target->id->value
            || $input->request->timeouts->totalTimeoutMs > RowValues::colInt($root, 'request_timeout_ms')
            || $input->request->timeouts->connectTimeoutMs > RowValues::colInt($root, 'connect_timeout_ms')) {
            throw new AdmissionRejected('operation_intent_conflict');
        }
        $priorAttempts = $scope->connection->table(PackageSchema::tables()['attempts'])
            ->where('tenant_key', $tenant->value)->where('operation_id', $rootId)->get(['snapshot']);
        $distinctLimit = min($input->request->timeouts->maxDistinctRoutes, $input->policy->timeouts->maxDistinctRoutes);
        $usedRoutes = [];
        foreach ($priorAttempts as $attempt) {
            $prior = RowValues::jsonAssoc(RowValues::colStr($attempt, 'snapshot'));
            $usedRoutes[RowValues::str($prior['route_id'] ?? null)] = true;
            if (isset($prior['max_distinct_routes'])) {
                $distinctLimit = min($distinctLimit, RowValues::int($prior['max_distinct_routes']));
            }
        }
        if (! isset($usedRoutes[$input->route->id->value]) && count($usedRoutes) >= $distinctLimit) {
            throw new AdmissionRejected('distinct_route_limit');
        }
        $evidence = $this->catalog->lock($input, $tx);
        if ($input->route->endpointId !== null) {
            $endpoint = $scope->connection->table(PackageSchema::tables()['endpoints'])->where('id', $input->route->endpointId->value)->first()
                ?? throw new AdmissionRejected('prepared_credential_conflict');
            if (RowValues::colNullableStr($endpoint, 'credential_ref') !== $wire->credential?->reference->reference
                || ($wire->credential !== null && RowValues::colNullableInt($endpoint, 'credential_version') !== $wire->credential->reference->version)) {
                throw new AdmissionRejected('prepared_credential_conflict');
            }
            if ($wire->proxy !== null && (RowValues::colStr($endpoint, 'protocol') !== $wire->proxy->endpoint->protocol->value
                || RowValues::colStr($endpoint, 'host') !== $wire->proxy->endpoint->host
                || RowValues::colInt($endpoint, 'port') !== $wire->proxy->endpoint->port)) {
                throw new AdmissionRejected('prepared_proxy_conflict');
            }
        }
        foreach ($this->definitions->selectExtensions(ExtensionKind::LockedAdmissionGuard, $this->guardIds) as $definition) {
            if (! $definition->implementation instanceof LockedAdmissionGuard) {
                throw new AdmissionRejected('invalid_admission_guard');
            }
            $this->locks->guard($definition->id, $definition->implementation, $input->request, $ownerState, $tx);
        }
        $own = $this->demands->resolveBudgets($tenant, $input->request, $input->selection, $input->additionalScopes());
        new ExtensionRuntime($this->definitions)->assertSharedBudgets($own, $tenant, $this->contributorIds);
        $locked = $this->demands->lockBudgets($own, $tx);
        $this->quotas->lockWindows($locked, $scheduled->permitId->value);
        $demand = $this->locks->rows(AuthorityLockTier::Demand, $tenant, [$scheduled->ticket->demandId->value], $tx)[0];
        $permit = $this->locks->rows(AuthorityLockTier::Permit, $tenant, [$scheduled->permitId->value], $tx)[0];
        $permitId = RowValues::colStr($permit, 'id');
        if (RowValues::colStr($permit, 'state') !== PermitState::Reserved->value) {
            throw new AdmissionRejected(RowValues::colNullableStr($permit, 'authorized_at') !== null ? 'already_claimed' : 'permit_terminal');
        }
        PermitState::Reserved->transitionTo(PermitState::Authorized);
        if (RowValues::colNullableStr($permit, 'reservation_intent') === null
            || RowValues::colStr($permit, 'demand_id') !== RowValues::colStr($demand, 'id')
            || RowValues::colStr($permit, 'operation_id') !== $rootId
            || RowValues::colNullableStr($permit, 'wire_fingerprint') !== $wire->request->fingerprint()->hex
            || RowValues::colInt($permit, 'cost') !== 1
            || RowValues::colStr($permit, 'route_id') !== $input->route->id->value
            || RowValues::colStr($permit, 'target_id') !== $input->target->id->value
            || RowValues::colStr($demand, 'intent_hash') !== DemandIntent::hash($input->request, $input->selection, $own)
            || RowValues::colStr($demand, 'traffic_class') !== $input->request->trafficClass) {
            throw new AdmissionRejected('claim_fingerprint_conflict');
        }
        $stored = RowValues::jsonAssoc(RowValues::colStr($permit, 'reservation_evidence'));
        $storedVersions = is_array($stored['versions'] ?? null) ? $stored['versions'] : [];
        $storedEpochs = is_array($stored['epochs'] ?? null) ? $stored['epochs'] : [];
        $storedQuota = is_array($stored['quota'] ?? null) ? $stored['quota'] : [];
        $evidence['versions']['operation:'.$rootId] = RowValues::colInt($root, 'revision');
        foreach ($locked as $budget) {
            $evidence['versions']['budget:'.RowValues::colStr($budget, 'id')] = RowValues::colInt($budget, 'revision');
        }
        ksort($storedVersions);
        ksort($evidence['versions']);
        ksort($storedEpochs);
        ksort($evidence['epochs']);
        if ($storedVersions !== $evidence['versions'] || $storedEpochs !== $evidence['epochs']) {
            throw new AdmissionRejected('claim_evidence_changed');
        }
        $references = $this->permits->references($permitId);
        $expected = array_map(static fn (stdClass $row): string => RowValues::colStr($row, 'tenant_key').':'.RowValues::colStr($row, 'id'), $locked);
        $actual = array_map(static fn (stdClass $row): string => RowValues::colStr($row, 'budget_tenant_key').':'.RowValues::colStr($row, 'budget_id'), $references);
        sort($expected);
        sort($actual);
        if ($expected !== $actual || array_filter($references, static fn (stdClass $r): bool => RowValues::colStr($r, 'state') !== 'reserved' || RowValues::colInt($r, 'cost') !== 1 || RowValues::colNullableStr($r, 'quota_window_id') !== null) !== []) {
            throw new AdmissionRejected('reservation_accounting_conflict');
        }
        $this->quotaService?->assertWindow($permitId, intdiv($input->request->timeouts->totalWithCleanupMarginNs(), 1000), $tenant, $input->operation->operationId);
        $quotaPlan = $this->quotas->plan($locked, $input->constraints, new DateTimeImmutable(RowValues::colStr($permit, 'not_before')), $input->request->trafficClass, $input->request->url->origin->budgetScopeKey());
        $quotaRefs = $this->quotas->references($permitId);
        $expectedQuotas = array_keys($quotaPlan);
        $actualQuotas = array_map(static fn (stdClass $ref): string => RowValues::colStr($ref, 'window_id'), $quotaRefs);
        sort($actualQuotas);
        if ($expectedQuotas !== $actualQuotas || count($quotaPlan) !== count($storedQuota)
            || array_filter($quotaRefs, static fn (stdClass $ref): bool => RowValues::colStr($ref, 'state') !== 'reserved' || RowValues::colInt($ref, 'cost') !== 1) !== []) {
            throw new AdmissionRejected('quota_ledger_conflict');
        }
        if ($quotaPlan !== [] && $this->quotaService === null) {
            throw new AdmissionRejected('quota_service_required');
        }
        foreach ($quotaPlan as $id => $window) {
            $storedEntry = is_array($storedQuota[$id] ?? null) ? $storedQuota[$id] : [];
            if (($storedEntry['limit'] ?? null) !== $window['limit']) {
                throw new AdmissionRejected('quota_configuration_changed');
            }
        }
        $snapshots = $this->budgets->read($locked, $input->constraints);
        $limits = RowValues::jsonAssoc(RowValues::colStr($permit, 'reservation_limits'));
        foreach ($snapshots as $id => $snapshot) {
            $limitEntry = is_array($limits[$id] ?? null) ? $limits[$id] : [];
            if (($limitEntry['interval'] ?? null) !== $snapshot['gcra']->intervalUs
                || ($limitEntry['burst'] ?? null) !== $snapshot['gcra']->burst || ($limitEntry['cap'] ?? null) !== $snapshot['cap']) {
                throw new AdmissionRejected('budget_configuration_changed');
            }
        }
        $this->budgets->planSend($snapshots, DatabaseClock::micros(DatabaseClock::now($scope->connection)));
        $origin = $input->request->url->origin;
        $cooldownKeys = [$origin->budgetScopeKey()];
        foreach ($this->cooldownScopes as $dimension) {
            $cooldownKeys[] = match ($dimension) {
                'route-origin' => $origin->routeScopeKey($input->route->id),
                'egress-origin' => $origin->egressScopeKey($input->route->egress->key),
                default => throw new InvalidArgumentException('Unsupported health cooldown scope.'),
            };
        }
        $probeClaimRow = $input->operation->probeToken === null ? null : new ProbeClaims($scope)->find($input->operation->probeToken->value);
        $safe = ['route_id' => $input->route->id->value, 'endpoint_id' => $input->route->endpointId?->value,
            'credential_version' => $input->selection->credentialVersion, 'fingerprint' => $input->request->fingerprint()->hex,
            'origin' => $input->request->url->origin->value(), 'egress_key' => $input->route->egress->key,
            'egress_pool_fallback' => $input->route->egress->conservativePoolFallback, 'target_id' => $input->target->id->value,
            'profile_id' => $input->profile->id->value, 'policy_revision' => $input->request->policyRevision,
            'outcome_policy_key' => $input->policy->outcomePolicyKey,
            'health_interpretation' => ($this->outcomes ?? new OutcomePolicyRegistry)->healthInterpretation($input->policy->outcomePolicyKey),
            'profile_route_set' => array_map(static fn (Uuid $id): string => $id->value, $input->profile->memberRouteIds),
            'route_owner_tenant_key' => $input->route->tenantKey->value,
            'independence_group' => $input->route->independenceGroup,
            'probe_token' => $input->operation->probeToken?->value,
            'probe_epochs' => new ProbeClaims($scope)->consume($input),
            'probe_until' => $probeClaimRow === null ? null : RowValues::colStr($probeClaimRow, 'expires_at'),
            'purpose' => $input->operation->purpose->value,
            'cooldown_budget_ids' => array_values(array_map(
                static fn (stdClass $b): string => RowValues::colStr($b, 'id'),
                array_filter($locked, static fn (stdClass $b): bool => in_array(RowValues::colStr($b, 'scope_key'), $cooldownKeys, true)),
            )),
            'max_distinct_routes' => $distinctLimit,
            'versions' => $evidence['versions'], 'epochs' => $evidence['epochs'], 'quota' => array_keys($quotaPlan), 'dispatch' => 'unknown'];
        try {
            $authorized = $this->permits->authorize($permit, $input, $ownerState, $snapshots, $safe, $quotaPlan);
        } catch (AdmissionRejected $rejected) {
            $this->quotaService?->assertWindow($permitId, intdiv($input->request->timeouts->totalWithCleanupMarginNs(), 1000), $tenant, $input->operation->operationId);
            throw $rejected;
        }
        $at = new DateTimeImmutable(RowValues::colStr($authorized, 'authorized_at'));
        $this->quotas->consume($permitId, $at);
        $this->budgets->applySend($this->budgets->planSend($snapshots, DatabaseClock::micros($at)));
        $candidate = $this->transactions->issueCandidate(new Uuid($permitId), $input->request->operationId,
            $input->request->physicalAttemptId, $input->request->fingerprint(), $at, new DateTimeImmutable(RowValues::colStr($authorized, 'expires_at')), $tx);
        $snapshot = new AuthorizedWireSnapshot(new Uuid($permitId), $input->route->id, $input->route->kind, $input->route->endpointId,
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
            if ($permit === null || RowValues::colStr($permit, 'state') !== 'authorized') {
                return;
            }
            $permitId = RowValues::colStr($permit, 'id');
            PermitState::Authorized->transitionTo(PermitState::UnknownAfterAuthorization);
            $db->table($t['permits'])->where('id', $permitId)->update(['state' => 'unknown_after_authorization']);
            $db->table($t['attempts'])->where('permit_id', $permitId)->where('status', 'authorized')->update([
                'status' => 'unknown_after_authorization', 'dispatch_refused_at' => $db->raw('clock_timestamp()'),
                'dispatch_outcome' => 'not_dispatched']);
        });
    }
}
