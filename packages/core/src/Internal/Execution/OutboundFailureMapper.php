<?php

declare(strict_types=1);

namespace Proxyma\Internal\Execution;

use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\DemandWait;
use Proxyma\Domain\Execution\BinaryBodyArtifact;
use Proxyma\Domain\Execution\BlockedReason;
use Proxyma\Domain\Execution\DeferralReason;
use Proxyma\Domain\Execution\ExecutionBlocked;
use Proxyma\Domain\Execution\ExecutionDeferred;
use Proxyma\Domain\Execution\ExecutionResult;
use Proxyma\Domain\Execution\ResponseDescriptor;
use Proxyma\Domain\Execution\RetryAt;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Outcomes\ResolvedOutcome;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Domain\Routing\SelectionResult;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\DatabaseClock;
use Proxyma\Infrastructure\Database\PackageSchema;
use Throwable;

/** Maps admission, selection, and retry failures into terminal execution outcomes. */
#[Internal]
final readonly class OutboundFailureMapper
{
    public function __construct(
        private ConnectionScope $scope,
    ) {}

    /**
     * @param  list<Uuid>  $attemptIds
     */
    public function mapUnselectedRoute(
        TenantKey $tenant,
        SelectionResult $selectionResult,
        array $attemptIds,
        ?ResponseDescriptor $lastDeliveredResponse,
    ): ExecutionResult {
        $holds = $selectionResult->snapshot->holds;
        if (in_array('empty_profile', $holds, true)) {
            $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

            return new ExecutionBlocked(BlockedReason::EmptyProfile, ['selection_reason' => $selectionResult->reason], $attemptIds);
        }
        if (in_array('target_missing_or_retired', $holds, true)) {
            $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

            return new ExecutionBlocked(BlockedReason::TargetRetired, ['selection_reason' => $selectionResult->reason], $attemptIds);
        }

        $candidateReasons = [];
        foreach ($selectionResult->snapshot->candidates as $candidate) {
            foreach ($candidate->exclusions as $reason) {
                $candidateReasons[$reason] = true;
            }
        }
        foreach ($selectionResult->selectionExclusions as $reason) {
            $candidateReasons[$reason] = true;
        }

        if (isset($candidateReasons['request_origin_mismatch'])) {
            $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

            return new ExecutionBlocked(BlockedReason::UrlPolicyDenied, ['selection_reason' => 'request_origin_mismatch'], $attemptIds);
        }

        if (isset($candidateReasons['direct_not_allowed']) && count($candidateReasons) === 1) {
            $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

            return new ExecutionBlocked(BlockedReason::DirectRouteNotAllowed, ['selection_reason' => 'direct_not_allowed'], $attemptIds);
        }

        if (isset($candidateReasons['route_permission_denied']) || isset($candidateReasons['shared_grant_missing_or_retired'])) {
            $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

            return new ExecutionBlocked(BlockedReason::CrossTenantDenied, ['selection_reason' => 'route_access_denied'], $attemptIds);
        }

        if (isset($candidateReasons['quota_exhausted'])) {
            return new ExecutionDeferred(
                DeferralReason::QuotaWindowExhausted,
                new RetryAt($this->nowWall()->modify('+60 seconds')),
                $attemptIds,
                $lastDeliveredResponse,
            );
        }

        if (isset($candidateReasons['route_health_unavailable'])) {
            if ($this->hasCredentialHoldOnCandidates($tenant, $selectionResult)) {
                $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                return new ExecutionBlocked(BlockedReason::CredentialHold, ['selection_reason' => 'route_credential_hold'], $attemptIds);
            }

            return new ExecutionDeferred(
                DeferralReason::NoHealthyRoute,
                new RetryAt($this->nowWall()->modify('+5 seconds')),
                $attemptIds,
                $lastDeliveredResponse,
            );
        }

        if ($holds !== [] || $selectionResult->reason === 'configuration_hold') {
            $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

            return new ExecutionBlocked(
                BlockedReason::ConfigurationHold,
                ['selection_reason' => $selectionResult->reason, 'holds' => implode(',', $holds)],
                $attemptIds,
            );
        }

        return new ExecutionDeferred(
            DeferralReason::NoHealthyRoute,
            new RetryAt($this->nowWall()->modify('+5 seconds')),
            $attemptIds,
            $lastDeliveredResponse,
        );
    }

    /**
     * @param  list<Uuid>  $attemptIds
     */
    public function mapExhaustedRouteRotation(
        TenantKey $tenant,
        SelectionResult $selectionResult,
        array $attemptIds,
        ?ResponseDescriptor $lastDeliveredResponse,
    ): ExecutionResult {
        if ($this->hasCredentialHoldOnCandidates($tenant, $selectionResult)) {
            $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

            return new ExecutionBlocked(
                BlockedReason::CredentialHold,
                ['reason' => 'all_routes_in_credential_hold'],
                $attemptIds,
            );
        }

        foreach ($selectionResult->snapshot->candidates as $candidate) {
            if (in_array('direct_not_allowed', $candidate->exclusions, true)) {
                $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                return new ExecutionBlocked(
                    BlockedReason::DirectRouteNotAllowed,
                    ['reason' => 'direct_fallback_denied_after_proxy_failure'],
                    $attemptIds,
                );
            }
        }

        return new ExecutionDeferred(
            DeferralReason::AttemptsExhausted,
            new RetryAt($this->nowWall()),
            $attemptIds,
            $lastDeliveredResponse,
        );
    }

    /**
     * @param  list<Uuid>  $attemptIds
     */
    public function mapDemandWait(
        DemandWait $wait,
        array $attemptIds,
        ?ResponseDescriptor $lastDeliveredResponse,
    ): ExecutionDeferred {
        $reason = match ($wait->reason) {
            'rate_wait', 'rate_limit' => DeferralReason::RateLimit,
            'capacity_wait', 'fifo_predecessor_wait' => DeferralReason::CapacityWait,
            'quota_window_wait', 'quota_exhausted' => DeferralReason::QuotaWindowExhausted,
            'slice_exhausted', 'work_budget_exhausted' => DeferralReason::SliceExhausted,
            default => DeferralReason::CapacityWait,
        };

        return new ExecutionDeferred($reason, $wait->retryAt, $attemptIds, $lastDeliveredResponse);
    }

    /**
     * @param  list<Uuid>  $attemptIds
     */
    public function mapAdmissionRejected(
        AdmissionRejected $rejected,
        array $attemptIds,
        ?ResponseDescriptor $lastDeliveredResponse,
    ): ExecutionResult {
        $deferredReason = match ($rejected->reason) {
            'operation_deadline_out_of_bounds', 'reservation_deadline', 'claim_deadline', 'slice_exhausted', 'work_budget_exhausted' => DeferralReason::SliceExhausted,
            'quota_exhausted', 'quota_window_exhausted' => DeferralReason::QuotaWindowExhausted,
            'health_unavailable', 'route_health_unavailable', 'target_health_unavailable' => DeferralReason::NoHealthyRoute,
            'physical_attempt_limit_reached', 'operation_attempt_limit', 'distinct_route_limit' => DeferralReason::AttemptsExhausted,
            default => null,
        };

        if ($deferredReason !== null) {
            return new ExecutionDeferred(
                $deferredReason,
                new RetryAt($this->nowWall()->modify('+1 second')),
                $attemptIds,
                $lastDeliveredResponse,
            );
        }

        $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

        $blockedReason = match ($rejected->reason) {
            'owner_lost', 'operation_owner_lost', 'owner_snapshot_stale', 'operation_not_active' => BlockedReason::OwnerLost,
            'owner_paused' => BlockedReason::OwnerPaused,
            'empty_profile' => BlockedReason::EmptyProfile,
            'direct_not_allowed' => BlockedReason::DirectRouteNotAllowed,
            'cross_tenant_denied', 'shared_grant_changed', 'shared_grant_missing_or_retired' => BlockedReason::CrossTenantDenied,
            'target_missing', 'target_retired' => BlockedReason::TargetRetired,
            'prepared_credential_conflict', 'credential_hold' => BlockedReason::CredentialHold,
            'wire_fingerprint_conflict', 'demand_fingerprint_conflict', 'reservation_fingerprint_conflict', 'claim_fingerprint_conflict' => BlockedReason::WireFingerprintConflict,
            'already_claimed', 'permit_already_claimed' => BlockedReason::AlreadyClaimed,
            default => BlockedReason::ConfigurationHold,
        };

        return new ExecutionBlocked(
            $blockedReason,
            ['admission_reason' => $rejected->reason],
            $attemptIds,
        );
    }

    /**
     * @param  list<Uuid>  $attemptIds
     */
    public function mapExhaustedRetries(
        ResolvedOutcome $outcome,
        array $attemptIds,
        ?ResponseDescriptor $lastDeliveredResponse,
    ): ExecutionResult {
        if ($outcome->technical->holdsProxyCredential) {
            $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

            return new ExecutionBlocked(
                BlockedReason::CredentialHold,
                ['business_meaning' => $outcome->classification->businessMeaning],
                $attemptIds,
            );
        }

        $retryAt = $outcome->retryAfter() ?? new RetryAt($this->nowWall());

        return new ExecutionDeferred(
            DeferralReason::AttemptsExhausted,
            $retryAt,
            $attemptIds,
            $lastDeliveredResponse,
        );
    }

    public function closeArtifactIfNeeded(?ResponseDescriptor $previous, ?ResponseDescriptor $keep): void
    {
        if ($previous === null || $previous === $keep) {
            return;
        }

        if ($previous->body instanceof BinaryBodyArtifact) {
            try {
                $previous->body->close();
            } catch (Throwable) {
            }
        }
    }

    private function hasCredentialHoldOnCandidates(TenantKey $tenant, SelectionResult $selectionResult): bool
    {
        $routesByOwner = [];
        foreach ($selectionResult->snapshot->candidates as $candidate) {
            if ($candidate->route === null || ! $candidate->route->isAccessibleBy($tenant)) {
                continue;
            }
            $routesByOwner[$candidate->route->tenantKey->value][] = $candidate->routeId->value;
        }
        if ($routesByOwner === []) {
            return false;
        }

        return PackageSchema::query($this->scope->connection, 'route_health')
            ->where(function (Builder $query) use ($routesByOwner): void {
                foreach ($routesByOwner as $owner => $routeIds) {
                    $query->orWhere(fn (Builder $owned): Builder => $owned->where('tenant_key', $owner)->whereIn('route_id', $routeIds));
                }
            })
            ->where('state', 'open')
            ->where('reason_code', 'like', 'credential_hold:%')
            ->exists();
    }

    private function nowWall(): DateTimeImmutable
    {
        return DatabaseClock::now($this->scope->connection);
    }
}
