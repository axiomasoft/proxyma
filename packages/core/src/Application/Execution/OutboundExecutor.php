<?php

declare(strict_types=1);

namespace Proxyma\Application\Execution;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Proxyma\Application\Admission\ClaimSend;
use Proxyma\Application\Admission\OperationCommands;
use Proxyma\Application\Admission\ReservationService;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Extensions\AttemptObservers;
use Proxyma\Application\Policy\PolicyResolver;
use Proxyma\Application\Recovery\CompletionCoordinator;
use Proxyma\Application\Routing\RouteSelector;
use Proxyma\Contracts\CatalogQueries;
use Proxyma\Contracts\DestinationResolver;
use Proxyma\Contracts\OutboundClient;
use Proxyma\Contracts\PhysicalTransport;
use Proxyma\Contracts\PolicyResolver as PolicyResolverContract;
use Proxyma\Contracts\SecretResolver;
use Proxyma\Contracts\TlsTrustPolicy;
use Proxyma\Contracts\WorkBudget;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\DemandWait;
use Proxyma\Domain\Catalog\Endpoint;
use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Catalog\RouteProfile;
use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\BinaryBodyArtifact;
use Proxyma\Domain\Execution\BlockedReason;
use Proxyma\Domain\Execution\DeferralReason;
use Proxyma\Domain\Execution\DemandPurpose;
use Proxyma\Domain\Execution\Duration;
use Proxyma\Domain\Execution\ExecutionBlocked;
use Proxyma\Domain\Execution\ExecutionContext;
use Proxyma\Domain\Execution\ExecutionDeferred;
use Proxyma\Domain\Execution\ExecutionResult;
use Proxyma\Domain\Execution\ExecutionSucceeded;
use Proxyma\Domain\Execution\ExecutionUnknownAfterAuthorization;
use Proxyma\Domain\Execution\IdempotencyClass;
use Proxyma\Domain\Execution\OutboundRequest;
use Proxyma\Domain\Execution\OutcomeDecision;
use Proxyma\Domain\Execution\ReadOnlyAttemptObservation;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\ResponseDescriptor;
use Proxyma\Domain\Execution\RetryAt;
use Proxyma\Domain\Execution\RouteSelection;
use Proxyma\Domain\Execution\RouteSelectionContext;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Execution\TransportFailure;
use Proxyma\Domain\Execution\TransportMetrics;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Outcomes\AttemptFacts;
use Proxyma\Domain\Outcomes\ResolvedOutcome;
use Proxyma\Domain\Policy\EffectivePolicy;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Domain\Routing\SelectionResult;
use Proxyma\Infrastructure\Database\CatalogRowMapper;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\DatabaseClock;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Health\ProbeClaimRecord;
use Proxyma\Infrastructure\Telemetry\OperationsTelemetry;
use Throwable;

final readonly class OutboundExecutor implements OutboundClient
{
    /**
     * @param  (Closure(int): void)|null  $sleepUs
     * @param  (Closure(): int)|null  $monotonicClockNs
     */
    public function __construct(
        private ConnectionScope $scope,
        private CatalogQueries $catalog,
        private PolicyResolverContract $policies,
        private DestinationResolver $destinations,
        private RouteSelector $selector,
        private ProxyResolver $proxies,
        private SecretResolver $secrets,
        private TlsTrustPolicy $tlsTrust,
        private WireSealer $sealer,
        private OperationCommands $operations,
        private ReservationService $reservations,
        private ClaimSend $claims,
        private PhysicalTransport $transport,
        private OutcomePolicyRegistry $outcomes,
        private CompletionCoordinator $completion,
        private ?Closure $sleepUs = null,
        private ?Closure $monotonicClockNs = null,
        private ?OperationsTelemetry $telemetry = null,
        private ?AttemptObservers $observers = null,
    ) {}

    public function execute(OutboundRequest $request, ExecutionContext $context): ExecutionResult
    {
        $result = $this->executeLoop($request, $context);
        if ($result instanceof ExecutionBlocked || $result instanceof ExecutionDeferred) {
            try {
                $this->telemetry?->blocked($result->reason->value, $request->operationId);
            } catch (Throwable) {
                // Ошибка экспортера не меняет уже принятое решение admission.
            }
        }

        return $result;
    }

    private function executeLoop(OutboundRequest $request, ExecutionContext $context): ExecutionResult
    {
        $this->scope->assertOutsideTransaction();
        if (! $request->operationId->equals($context->operation->operationId)) {
            throw new InvalidArgumentException('OutboundRequest operationId must match ExecutionContext operation correlation.');
        }

        $context = new ExecutionContext($context->operation, $context->subjects, $context->remainingSlice,
            min($context->monotonicDeadlineNs, $this->nowMonotonicNs() + $context->remainingSlice->nanos()));

        $tenant = $context->operation->owner->tenantKey;
        $currentMethod = $request->method;
        $currentUrl = $request->url;
        $currentHeaders = $request->headers;
        $currentBody = $request->body;
        $currentIdempotency = $request->idempotency;

        /** @var list<Uuid> $attemptIds */
        $attemptIds = [];
        /** @var list<Uuid> $triedRouteIds */
        $triedRouteIds = [];
        /** @var array<string, true> $excludedRetryRouteIds */
        $excludedRetryRouteIds = [];
        /** @var array<string, list<string>> $failedAddressesByHost */
        $failedAddressesByHost = [];
        /** @var array<string, true> $visitedUrls */
        $visitedUrls = [$currentUrl->reveal() => true];

        $hopAttempt = 1;
        $redirectHop = 0;
        $totalSends = $this->scope->connection->table(PackageSchema::tables()['permits'])
            ->where('tenant_key', $tenant->value)->where('operation_id', $request->operationId->value)
            ->whereNotNull('authorized_at')->count();
        foreach ($this->scope->connection->table(PackageSchema::tables()['permits'])
            ->where('tenant_key', $tenant->value)->where('operation_id', $request->operationId->value)
            ->whereNotNull('authorized_at')->distinct()->pluck('route_id') as $routeId) {
            $triedRouteIds[] = new Uuid($routeId);
        }
        $preferUntried = false;
        $sameRouteRetry = false;
        $previousRouteId = null;
        $pendingDelayMs = 0;
        $lastDeliveredResponse = null;
        $operationProvisioned = false;
        $operationLimit = $this->scope->connection->table(PackageSchema::tables()['operations'])
            ->where('tenant_key', $tenant->value)->where('id', $request->operationId->value)->value('max_physical_attempts');

        try {
            while (true) {
                $this->scope->assertOutsideTransaction();

                try {
                    $target = $this->catalog->findTarget($tenant, $request->targetId);
                } catch (AuthorizationDenied) {
                    $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                    return new ExecutionBlocked(BlockedReason::CrossTenantDenied, ['stage' => 'target_read'], $attemptIds);
                }

                if ($target === null || $target->isRetired()) {
                    $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                    return new ExecutionBlocked(BlockedReason::TargetRetired, ['target_id' => $request->targetId->value], $attemptIds);
                }

                try {
                    $rawPolicy = $this->resolveEffectivePolicy($context->subjects, $request->targetId, $request->trafficKey);
                } catch (AuthorizationDenied) {
                    $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                    return new ExecutionBlocked(BlockedReason::CrossTenantDenied, ['stage' => 'policy_resolve'], $attemptIds);
                }

                $profileId = $this->resolveProfileId($tenant, $context->operation->operationId, $rawPolicy);
                if ($profileId === null) {
                    $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                    return new ExecutionBlocked(BlockedReason::EmptyProfile, ['target_id' => $request->targetId->value], $attemptIds);
                }

                try {
                    $profile = $this->catalog->findProfile($tenant, $profileId);
                } catch (AuthorizationDenied) {
                    $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                    return new ExecutionBlocked(BlockedReason::CrossTenantDenied, ['stage' => 'profile_read'], $attemptIds);
                }

                if ($profile === null) {
                    $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                    return new ExecutionBlocked(BlockedReason::ConfigurationHold, ['reason' => 'profile_missing_or_retired'], $attemptIds);
                }

                if ($profile->isEmpty()) {
                    $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                    return new ExecutionBlocked(BlockedReason::EmptyProfile, ['profile_id' => $profile->id->value], $attemptIds);
                }

                $effectivePolicy = $this->intersectPolicy($tenant, $profile, $rawPolicy, $request);
                $effectiveTimeouts = $effectivePolicy->timeouts;
                try {
                    $this->outcomes->resolve($effectivePolicy->outcomePolicyKey);
                } catch (\LogicException) {
                    return new ExecutionBlocked(BlockedReason::ConfigurationHold, ['reason' => 'outcome_policy_unregistered'], $attemptIds);
                }

                // Проверяем все границы цикла ДО локального DNS и ДО резервирования permit.
                if (($context->operation->purpose === DemandPurpose::Probe && $totalSends > 0)
                    || ($request->sendLimit !== null && $totalSends >= $request->sendLimit)) {
                    return new ExecutionDeferred(DeferralReason::AttemptsExhausted, new RetryAt($this->nowWall()), $attemptIds, $lastDeliveredResponse);
                }

                $operationLimit ??= $effectiveTimeouts->maxAttempts;
                if ($totalSends >= min((int) $operationLimit, $effectiveTimeouts->maxAttempts)) {
                    return new ExecutionDeferred(DeferralReason::AttemptsExhausted, new RetryAt($this->nowWall()), $attemptIds, $lastDeliveredResponse);
                }

                if ($redirectHop > $effectiveTimeouts->maxRedirects) {
                    $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                    return new ExecutionBlocked(
                        BlockedReason::UrlPolicyDenied,
                        ['redirect_reason' => 'max_redirects_exceeded', 'max_redirects' => $effectiveTimeouts->maxRedirects],
                        $attemptIds,
                    );
                }

                if ($currentBody !== null && ! $currentBody->isReplayable() && $totalSends > 0) {
                    $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                    return new ExecutionBlocked(
                        BlockedReason::PolicyReview,
                        ['reason' => 'non_replayable_body_second_attempt'],
                        $attemptIds,
                    );
                }

                if ($preferUntried && count($triedRouteIds) >= $effectiveTimeouts->maxDistinctRoutes) {
                    return new ExecutionDeferred(DeferralReason::AttemptsExhausted, new RetryAt($this->nowWall()), $attemptIds, $lastDeliveredResponse);
                }

                $attemptBudgetMs = $effectiveTimeouts->totalTimeoutMs + $effectiveTimeouts->cleanupMarginMs;
                if ($pendingDelayMs > 0) {
                    if (! $this->canFitWindowMs($context, $pendingDelayMs + $attemptBudgetMs)) {
                        return new ExecutionDeferred(
                            DeferralReason::SliceExhausted,
                            new RetryAt($this->nowWall()->modify('+'.$pendingDelayMs.' milliseconds')),
                            $attemptIds,
                            $lastDeliveredResponse,
                        );
                    }
                    $this->sleep($pendingDelayMs * 1000);
                    $pendingDelayMs = 0;
                }

                if (! $this->canFitWindowMs($context, $attemptBudgetMs)) {
                    return new ExecutionDeferred(DeferralReason::SliceExhausted, new RetryAt($this->nowWall()), $attemptIds, $lastDeliveredResponse);
                }

                $cooldown = $this->scope->connection->table(PackageSchema::tables()['budgets'])
                    ->whereIn('tenant_key', array_unique([$tenant->value, TenantKey::SYSTEM]))
                    ->where('scope_key', $currentUrl->origin->budgetScopeKey())
                    ->whereRaw('cooldown_until > clock_timestamp()')->max('cooldown_until');
                if (is_string($cooldown)) {
                    return new ExecutionDeferred(DeferralReason::RateLimit, new RetryAt(new DateTimeImmutable($cooldown)), $attemptIds, $lastDeliveredResponse);
                }

                try {
                    $destination = $this->destinations instanceof DestinationPolicy
                        ? $this->destinations->resolve($currentUrl, $context, $effectiveTimeouts->connectTimeoutMs, $target)
                        : $this->destinations->resolve($currentUrl, $context, $effectiveTimeouts->connectTimeoutMs);
                } catch (InvalidArgumentException $e) {
                    $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                    return new ExecutionBlocked(
                        BlockedReason::UrlPolicyDenied,
                        ['reason' => 'destination_policy_denied', 'message' => $e->getMessage()],
                        $attemptIds,
                    );
                } catch (Throwable) {
                    return new ExecutionDeferred(
                        DeferralReason::DependencyUnavailable,
                        new RetryAt($this->nowWall()->modify('+1 second')),
                        $attemptIds,
                        $lastDeliveredResponse,
                    );
                }

                $failedForHost = $failedAddressesByHost[$currentUrl->origin->host] ?? [];
                if ($failedForHost !== [] && count($destination->addresses) > 1) {
                    foreach ($destination->addresses as $candidateAddress) {
                        if (! in_array($candidateAddress, $failedForHost, true)) {
                            $destination = $destination->withAddress($candidateAddress);
                            break;
                        }
                    }
                }

                if (! $operationProvisioned) {
                    try {
                        $this->ensureOperationProvisioned($tenant, $context, $profile->id, $target->id, $effectiveTimeouts);
                        $operationProvisioned = true;
                    } catch (AdmissionRejected $e) {
                        return $this->mapAdmissionRejected($e, $attemptIds, $lastDeliveredResponse);
                    } catch (AuthorizationDenied) {
                        $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                        return new ExecutionBlocked(BlockedReason::CrossTenantDenied, ['stage' => 'operation_create'], $attemptIds);
                    }
                }

                $physicalAttemptId = Uuid::random();
                $wireRequest = new WireRequest(
                    operationId: $context->operation->operationId,
                    physicalAttemptId: $physicalAttemptId,
                    targetId: $request->targetId,
                    policyRevision: $effectivePolicy->effectiveRevision,
                    method: $currentMethod,
                    url: $currentUrl,
                    timeouts: $effectiveTimeouts,
                    deadlineAt: $context->operation->deadlineAt,
                    headers: $currentHeaders,
                    body: $currentBody,
                    idempotency: $currentIdempotency,
                    trafficClass: $request->trafficKey,
                    purpose: $context->operation->purpose,
                    responseProfile: $request->responseProfile,
                );

                $selectionContext = new RouteSelectionContext(
                    triedRouteIds: $triedRouteIds,
                    maxDistinctRoutes: $effectiveTimeouts->maxDistinctRoutes,
                    preferUntried: $preferUntried,
                    previousRouteId: $previousRouteId,
                );

                if ($context->operation->purpose === DemandPurpose::Probe) {
                    $probe = $this->scope->connection->table((new ProbeClaimRecord)->getTable())
                        ->where('id', $context->operation->probeToken->value)->where('tenant_key', $tenant->value)
                        ->where('operation_id', $context->operation->operationId->value)->where('target_id', $target->id->value)
                        ->whereNull('physical_attempt_id')->whereRaw('expires_at > clock_timestamp()')->first();
                    try {
                        $route = $probe === null ? null : $this->catalog->findRoute($tenant, new Uuid($probe->route_id));
                    } catch (AuthorizationDenied) {
                        return new ExecutionBlocked(BlockedReason::CrossTenantDenied, ['stage' => 'probe_route_read'], $attemptIds);
                    }
                    if ($route === null || ! $profile->containsRoute($route->id)) {
                        return new ExecutionBlocked(BlockedReason::ConfigurationHold, ['reason' => 'probe_ownership_lost'], $attemptIds);
                    }
                    $selection = new RouteSelection($route->id, $route->kind, $profile->id,
                        $route->endpointId, $route->credentialVersion, $route->egress, $route->independenceGroup);
                } else {
                    try {
                        $selectionResult = $this->selector->select($tenant, $profile->id, $effectivePolicy, $wireRequest, $selectionContext);
                    } catch (AuthorizationDenied) {
                        $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                        return new ExecutionBlocked(BlockedReason::CrossTenantDenied, ['stage' => 'route_select'], $attemptIds);
                    }

                    if ($selectionResult->route === null || $selectionResult->selection === null) {
                        return $this->mapUnselectedRoute($tenant, $selectionResult, $attemptIds, $lastDeliveredResponse);
                    }

                    $route = $selectionResult->route;
                    $selection = $selectionResult->selection;
                }
                if ($sameRouteRetry && ($previousRouteId === null || ! $route->id->equals($previousRouteId))) {
                    return new ExecutionDeferred(DeferralReason::NoHealthyRoute, new RetryAt($this->nowWall()->modify('+5 seconds')), $attemptIds, $lastDeliveredResponse);
                }

                if ($preferUntried && isset($excludedRetryRouteIds[$route->id->value])) {
                    $alternate = $this->findAlternateCandidate($selectionResult, $profile->id, $triedRouteIds, $excludedRetryRouteIds, $effectiveTimeouts->maxDistinctRoutes);
                    if ($alternate === null) {
                        return $this->mapExhaustedRouteRotation($tenant, $selectionResult, $attemptIds, $lastDeliveredResponse);
                    }
                    [$route, $selection] = $alternate;
                }

                $proxyDestination = null;
                $credential = null;
                $proxyTls = null;

                if ($route->kind === RouteKind::Proxy) {
                    if ($route->endpointId === null) {
                        $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                        return new ExecutionBlocked(BlockedReason::ConfigurationHold, ['reason' => 'proxy_endpoint_missing'], $attemptIds);
                    }

                    try {
                        $endpoint = $this->resolveEndpoint($tenant, $route);
                    } catch (AuthorizationDenied) {
                        $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                        return new ExecutionBlocked(BlockedReason::CrossTenantDenied, ['stage' => 'endpoint_read'], $attemptIds);
                    }

                    if ($endpoint === null || $endpoint->isRetired()) {
                        $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                        return new ExecutionBlocked(BlockedReason::ConfigurationHold, ['reason' => 'proxy_endpoint_retired'], $attemptIds);
                    }

                    try {
                        $proxyDestination = $this->proxies->resolve($endpoint, $effectiveTimeouts->connectTimeoutMs);
                    } catch (Throwable $e) {
                        $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                        return new ExecutionBlocked(
                            BlockedReason::ConfigurationHold,
                            ['reason' => 'proxy_resolution_failed', 'message' => $e->getMessage()],
                            $attemptIds,
                        );
                    }

                    if ($endpoint->credential !== null) {
                        try {
                            $credential = $this->secrets->resolve($endpoint->credential, $route->tenantKey);
                        } catch (AuthorizationDenied) {
                            $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                            return new ExecutionBlocked(BlockedReason::CrossTenantDenied, ['stage' => 'secret_resolve'], $attemptIds);
                        } catch (Throwable) {
                            $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                            return new ExecutionBlocked(BlockedReason::CredentialHold, ['reason' => 'proxy_secret_unavailable'], $attemptIds);
                        }

                        if ($credential === null) {
                            $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                            return new ExecutionBlocked(BlockedReason::CredentialHold, ['reason' => 'proxy_secret_missing'], $attemptIds);
                        }
                    }

                }

                try {
                    if ($route->kind === RouteKind::Proxy && $endpoint->protocol === ProxyProtocol::Https) {
                        $proxyTls = $this->tlsTrust->proxyConfigurationFor($endpoint->host);
                    }
                    $originTls = $this->tlsTrust->tlsConfigurationFor($currentUrl->origin, $request->targetId);
                    $sealedWire = $this->sealer->seal(
                        $wireRequest,
                        $route,
                        $destination,
                        $originTls,
                        $credential,
                        $proxyTls,
                        $proxyDestination,
                    );
                } catch (AdmissionRejected $rejected) {
                    return $this->mapAdmissionRejected($rejected, $attemptIds, $lastDeliveredResponse);
                } catch (Throwable) {
                    $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                    return new ExecutionBlocked(BlockedReason::ConfigurationHold, ['reason' => 'wire_preparation_failed'], $attemptIds);
                }

                $work = $this->workBudget($context);
                $reservedPermit = null;
                $claimedPermitId = null;

                try {
                    $reservationInput = $this->reservations->prepare(
                        $sealedWire->request,
                        $context->operation,
                        $selection,
                        $effectivePolicy,
                        $work,
                    );
                    $ticketOrWait = $this->reservations->enqueue($reservationInput, $work);
                    if ($ticketOrWait instanceof DemandWait) {
                        return $this->mapDemandWait($ticketOrWait, $attemptIds, $lastDeliveredResponse);
                    }

                    $permitOrWait = $this->reservations->reserve($ticketOrWait, $reservationInput);
                    if ($permitOrWait instanceof DemandWait) {
                        return $this->mapDemandWait($permitOrWait, $attemptIds, $lastDeliveredResponse);
                    }
                    $reservedPermit = $permitOrWait;

                    $nowBeforeClaim = $this->nowWall();
                    $waitToNotBeforeUs = DatabaseClock::micros($reservedPermit->slotAt) - DatabaseClock::micros($nowBeforeClaim);
                    if ($waitToNotBeforeUs > 0) {
                        $waitMs = intdiv($waitToNotBeforeUs + 999, 1000);
                        if (! $this->canFitWindowMs($context, $waitMs + $attemptBudgetMs)) {
                            $this->safeCancelPermit($tenant, $context->operation->operationId, $reservedPermit->permitId);

                            return new ExecutionDeferred(
                                DeferralReason::SliceExhausted,
                                new RetryAt($reservedPermit->slotAt),
                                $attemptIds,
                                $lastDeliveredResponse,
                            );
                        }
                        $this->sleep($waitToNotBeforeUs);
                    }

                    $candidate = $this->claims->claim($reservedPermit, $reservationInput, $sealedWire);
                    $claimedPermitId = $reservedPermit->permitId;
                    $reservedPermit = null;
                    $authorizedWire = $this->claims->activate($candidate);
                } catch (AdmissionRejected $e) {
                    if ($reservedPermit !== null) {
                        $this->safeCancelPermit($tenant, $context->operation->operationId, $reservedPermit->permitId);
                    }

                    return $this->mapAdmissionRejected($e, $attemptIds, $lastDeliveredResponse);
                } catch (AuthorizationDenied) {
                    if ($reservedPermit !== null) {
                        $this->safeCancelPermit($tenant, $context->operation->operationId, $reservedPermit->permitId);
                    }
                    $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                    return new ExecutionBlocked(BlockedReason::CrossTenantDenied, ['stage' => 'admission'], $attemptIds);
                } catch (Throwable) {
                    if ($reservedPermit !== null) {
                        $this->safeCancelPermit($tenant, $context->operation->operationId, $reservedPermit->permitId);
                    }

                    if ($claimedPermitId === null) {
                        $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                        return new ExecutionBlocked(BlockedReason::ConfigurationHold, ['reason' => 'admission_dependency_failed'], $attemptIds);
                    }

                    $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                    return new ExecutionUnknownAfterAuthorization(
                        $context->operation->operationId,
                        $physicalAttemptId,
                        $claimedPermitId,
                        $sealedWire->request->fingerprint(),
                        null,
                    );
                }

                $attemptIds[] = $physicalAttemptId;
                $triedRouteIds = $this->appendUniqueUuid($triedRouteIds, $route->id);
                $totalSends++;

                try {
                    $attemptResult = $this->transport->sendOnce($authorizedWire);
                } catch (Throwable) {
                    $attemptResult = AttemptResult::failed(TransportFailure::Protocol);
                }

                $facts = $this->buildAttemptFacts(
                    $attemptResult,
                    $sealedWire->request,
                    $route,
                    $effectivePolicy,
                    $hopAttempt,
                    $redirectHop,
                );

                $policyFailed = false;
                try {
                    $resolvedOutcome = $this->outcomes->evaluate(
                        $effectivePolicy->outcomePolicyKey,
                        $facts,
                        $attemptResult,
                        $sealedWire->request,
                        $this->nowWall(),
                    );
                } catch (Throwable) {
                    $policyFailed = true;
                    $resolvedOutcome = (new OutcomePolicyRegistry)->evaluate('default', $facts, $attemptResult, $sealedWire->request, $this->nowWall());
                }

                try {
                    $this->completion->recordOutcome(
                        $context->operation->operationId,
                        $physicalAttemptId,
                        $attemptResult,
                        $resolvedOutcome,
                    );
                } catch (Throwable) {
                    $this->closeArtifactIfNeeded($attemptResult->response, null);
                    $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                    return new ExecutionUnknownAfterAuthorization($context->operation->operationId, $physicalAttemptId,
                        $authorizedWire->snapshot->permitId, $sealedWire->request->fingerprint(), $attemptResult->failure);
                }

                $this->observers?->notify(new ReadOnlyAttemptObservation(
                    $context->operation->owner->tenantKey, $context->operation->operationId, $physicalAttemptId,
                    $route->id, $route->kind, $sealedWire->request->targetId, $sealedWire->request->url->origin,
                    '[redacted]', $sealedWire->request->fingerprint(), $attemptResult->head?->statusCode,
                    $attemptResult->failure, $resolvedOutcome->classification, $attemptResult->wireBytes, $attemptResult->decodedBytes,
                    $attemptResult->metrics ?? new TransportMetrics, $this->nowWall(),
                ));

                if ($attemptResult->response !== null) {
                    $this->closeArtifactIfNeeded($lastDeliveredResponse, $attemptResult->response);
                    $lastDeliveredResponse = $attemptResult->response;
                }

                if ($resolvedOutcome->isAmbiguousAfterAuthorization()) {
                    $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                    return new ExecutionUnknownAfterAuthorization(
                        $context->operation->operationId,
                        $physicalAttemptId,
                        $authorizedWire->snapshot->permitId,
                        $sealedWire->request->fingerprint(),
                        $attemptResult->failure,
                    );
                }

                $decision = $resolvedOutcome->decision();
                if ($policyFailed) {
                    $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                    return new ExecutionBlocked(BlockedReason::PolicyReview, ['reason' => 'outcome_policy_failed'], $attemptIds);
                }

                if ($resolvedOutcome->isRedirect() && $decision === OutcomeDecision::Accept) {
                    if ($redirectHop + 1 > $effectiveTimeouts->maxRedirects) {
                        $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                        return new ExecutionBlocked(
                            BlockedReason::UrlPolicyDenied,
                            ['redirect_reason' => 'max_redirects_exceeded', 'max_redirects' => $effectiveTimeouts->maxRedirects],
                            $attemptIds,
                        );
                    }

                    $location = $facts->locationHeader;
                    if ($location === null || trim($location) === '') {
                        $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                        return new ExecutionBlocked(
                            BlockedReason::UrlPolicyDenied,
                            ['redirect_reason' => 'missing_redirect_location'],
                            $attemptIds,
                        );
                    }

                    try {
                        $nextUrl = $this->resolveRedirectUrl($currentUrl, $location);
                    } catch (InvalidArgumentException $e) {
                        $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                        return new ExecutionBlocked(
                            BlockedReason::UrlPolicyDenied,
                            ['redirect_reason' => 'invalid_redirect_location', 'message' => $e->getMessage()],
                            $attemptIds,
                        );
                    }

                    if ($currentUrl->origin->scheme === 'https' && $nextUrl->origin->scheme === 'http') {
                        $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                        return new ExecutionBlocked(
                            BlockedReason::UrlPolicyDenied,
                            ['redirect_reason' => 'https_to_http_downgrade'],
                            $attemptIds,
                        );
                    }

                    $nextUrlKey = $nextUrl->reveal();
                    if (isset($visitedUrls[$nextUrlKey])) {
                        $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                        return new ExecutionBlocked(
                            BlockedReason::UrlPolicyDenied,
                            ['redirect_reason' => 'redirect_cycle'],
                            $attemptIds,
                        );
                    }

                    $statusCode = $facts->httpStatus ?? 0;
                    if ($statusCode === 303) {
                        $currentMethod = $currentMethod === 'HEAD' ? 'HEAD' : 'GET';
                        $currentBody = null;
                        $currentHeaders = $currentHeaders->withoutContentHeaders();
                        $currentIdempotency = IdempotencyClass::ReadOnly;
                    } elseif ($statusCode === 307 || $statusCode === 308) {
                        if ($currentIdempotency === IdempotencyClass::AmbiguousWrite) {
                            $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                            return new ExecutionBlocked(
                                BlockedReason::PolicyReview,
                                ['redirect_reason' => 'ambiguous_write_redirect_denied', 'status' => $statusCode],
                                $attemptIds,
                            );
                        }
                        if ($currentBody !== null && ! $currentBody->isReplayable()) {
                            $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                            return new ExecutionBlocked(
                                BlockedReason::PolicyReview,
                                ['redirect_reason' => 'non_replayable_redirect_body', 'status' => $statusCode],
                                $attemptIds,
                            );
                        }
                    } elseif ($statusCode === 301 || $statusCode === 302) {
                        if (($currentMethod !== 'GET' && $currentMethod !== 'HEAD')
                            || $currentBody !== null
                            || $currentIdempotency === IdempotencyClass::AmbiguousWrite) {
                            $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                            return new ExecutionBlocked(
                                BlockedReason::PolicyReview,
                                ['redirect_reason' => 'unsafe_redirect_method', 'status' => $statusCode],
                                $attemptIds,
                            );
                        }
                    } else {
                        $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                        return new ExecutionBlocked(
                            BlockedReason::PolicyReview,
                            ['redirect_reason' => 'unsupported_redirect_status', 'status' => $statusCode],
                            $attemptIds,
                        );
                    }

                    if (! $nextUrl->origin->equals($currentUrl->origin)) {
                        $currentHeaders = $currentHeaders->acrossOrigin(preserveContentType: $currentBody !== null);
                    }

                    $this->closeArtifactIfNeeded($lastDeliveredResponse, null);
                    $lastDeliveredResponse = null;

                    $visitedUrls[$nextUrlKey] = true;
                    $currentUrl = $nextUrl;
                    $redirectHop++;
                    $hopAttempt = 1;
                    $excludedRetryRouteIds = [];
                    $failedAddressesByHost = [];
                    $preferUntried = false;
                    $sameRouteRetry = false;
                    $previousRouteId = $route->id;
                    $pendingDelayMs = 0;

                    continue;
                }

                if ($decision === OutcomeDecision::Accept && $attemptResult->response !== null) {
                    return new ExecutionSucceeded(
                        $attemptResult->response,
                        $attemptIds,
                        $resolvedOutcome->classification,
                    );
                }

                if ($decision === OutcomeDecision::RetrySameRoute || $decision === OutcomeDecision::RetryDifferentRoute) {
                    if ($attemptResult->failure !== null && in_array($attemptResult->failure, [
                        TransportFailure::Connect,
                        TransportFailure::Timeout,
                        TransportFailure::Truncated,
                    ], true)) {
                        $failedAddressesByHost[$currentUrl->origin->host][] = $destination->address;
                    }

                    if ($totalSends >= $effectiveTimeouts->maxAttempts
                        || ($request->sendLimit !== null && $totalSends >= $request->sendLimit)) {
                        return $this->mapExhaustedRetries($resolvedOutcome, $attemptIds, $lastDeliveredResponse);
                    }

                    if ($currentBody !== null && ! $currentBody->isReplayable()) {
                        $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                        return new ExecutionBlocked(
                            BlockedReason::PolicyReview,
                            ['reason' => 'non_replayable_retry_body'],
                            $attemptIds,
                        );
                    }

                    if ($decision === OutcomeDecision::RetryDifferentRoute) {
                        $sameRouteRetry = false;
                        $excludedRetryRouteIds[$route->id->value] = true;
                        $preferUntried = true;
                        $previousRouteId = null;

                        if (count($triedRouteIds) >= $effectiveTimeouts->maxDistinctRoutes) {
                            $hasTriedEligible = false;
                            foreach ($triedRouteIds as $triedId) {
                                if (! isset($excludedRetryRouteIds[$triedId->value])) {
                                    $hasTriedEligible = true;
                                    break;
                                }
                            }
                            if (! $hasTriedEligible) {
                                return $this->mapExhaustedRetries($resolvedOutcome, $attemptIds, $lastDeliveredResponse);
                            }
                        }
                    } else {
                        $sameRouteRetry = true;
                        $preferUntried = false;
                        $previousRouteId = $route->id;
                    }

                    $pendingDelayMs = $this->resolveRetryDelayMs($effectivePolicy, $resolvedOutcome, $hopAttempt);
                    $hopAttempt++;

                    continue;
                }

                if ($decision === OutcomeDecision::Defer) {
                    $retryAt = $resolvedOutcome->retryAfter() ?? new RetryAt($this->nowWall()->modify('+60 seconds'));
                    $deferReason = $resolvedOutcome->deferralReason ?? DeferralReason::DependencyUnavailable;

                    return new ExecutionDeferred($deferReason, $retryAt, $attemptIds, $lastDeliveredResponse);
                }

                $this->closeArtifactIfNeeded($lastDeliveredResponse, null);

                $blockedReason = $resolvedOutcome->blockedReason ?? BlockedReason::PolicyReview;

                return new ExecutionBlocked(
                    $blockedReason,
                    [
                        'business_meaning' => $resolvedOutcome->classification->businessMeaning,
                        'decision' => $decision->value,
                    ],
                    $attemptIds,
                );
            }
        } catch (Throwable $e) {
            $this->closeArtifactIfNeeded($lastDeliveredResponse, null);
            throw $e;
        }
    }

    private function resolveEffectivePolicy(SubjectChain $subjects, Uuid $targetId, string $trafficKey): EffectivePolicy
    {
        if ($this->policies instanceof PolicyResolver) {
            return $this->policies->resolveEffective($subjects, $targetId, $trafficKey);
        }

        $explanation = $this->policies->explain($subjects, $targetId, $trafficKey);
        if ($explanation->effectivePolicy !== null) {
            return $explanation->effectivePolicy;
        }

        $snapshot = $this->policies->resolve($subjects, $targetId, $trafficKey);

        return new EffectivePolicy(
            tenantKey: $snapshot->tenantKey,
            subjectChain: $subjects,
            targetId: $snapshot->targetId,
            destinationPolicyKey: 'default',
            trafficKey: $trafficKey,
            routeProfileId: $snapshot->routeProfileId,
            routeProfileKey: 'default',
            routeProfileRevision: null,
            allowedProfileKeys: null,
            timeouts: $snapshot->timeouts,
            retryDelaysDisabled: false,
            allowDirectRoute: $snapshot->allowDirectRoute,
            strategyKey: 'uniform_random',
            outcomePolicyKey: $snapshot->outcomePolicyKey,
            deniedRoutes: [],
            allowedDestinations: null,
            budgets: [],
            values: [],
            disabledSettings: [],
            layerRevisions: $snapshot->layerRevisions,
            appliedLayers: [],
            explanations: [],
            effectiveRevision: $snapshot->effectiveRevision,
            policyHash: $snapshot->policyHash,
        );
    }

    private function resolveProfileId(TenantKey $tenant, Uuid $operationId, EffectivePolicy $policy): ?Uuid
    {
        if ($policy->routeProfileId !== null) {
            return $policy->routeProfileId;
        }

        $db = $this->scope->connection;
        $tables = PackageSchema::tables();

        $existingProfileId = $db->table($tables['operations'])
            ->where('tenant_key', $tenant->value)
            ->where('id', $operationId->value)
            ->value('profile_id');

        if (is_string($existingProfileId) && $existingProfileId !== '') {
            return new Uuid($existingProfileId);
        }

        $preferredKey = $policy->routeProfileKey ?? 'default';
        $preferredId = $db->table($tables['route_profiles'])
            ->where('tenant_key', $tenant->value)
            ->where('profile_key', $preferredKey)
            ->whereNull('retired_at')
            ->value('id');

        if (is_string($preferredId) && $preferredId !== '') {
            return new Uuid($preferredId);
        }

        return null;
    }

    private function intersectPolicy(
        TenantKey $tenant,
        RouteProfile $profile,
        EffectivePolicy $rawPolicy,
        OutboundRequest $request,
    ): EffectivePolicy {
        $isExplicit = static function (EffectivePolicy $p, string $key): bool {
            if ($p->explanations === []) {
                return true;
            }
            $exp = $p->explanations[$key] ?? null;

            return $exp !== null && ($exp->explicit || $exp->clamped);
        };

        $totalTimeoutMs = min($request->timeouts->totalTimeoutMs, $rawPolicy->timeouts->totalTimeoutMs);

        $connectTimeoutMs = min(
            $totalTimeoutMs,
            min($request->timeouts->connectTimeoutMs, $rawPolicy->timeouts->connectTimeoutMs),
        );

        $readTimeoutMs = min(
            $totalTimeoutMs,
            min($request->timeouts->readTimeoutMs, $rawPolicy->timeouts->readTimeoutMs),
        );

        $maxAttempts = min($request->timeouts->maxAttempts, $rawPolicy->timeouts->maxAttempts);

        $maxDistinctRoutes = min(
            $maxAttempts,
            $request->timeouts->maxDistinctRoutes,
            $rawPolicy->timeouts->maxDistinctRoutes,
        );

        $maxRedirects = min($request->timeouts->maxRedirects, $rawPolicy->timeouts->maxRedirects);

        $retryDelaysMs = $rawPolicy->retryDelaysDisabled
            ? []
            : ($isExplicit($rawPolicy, 'retry.delays_ms') && $rawPolicy->timeouts->retryDelaysMs !== []
                ? $rawPolicy->timeouts->retryDelaysMs
                : $request->timeouts->retryDelaysMs);

        $effectiveTimeouts = new RequestTimeoutPolicy(
            connectTimeoutMs: $connectTimeoutMs,
            readTimeoutMs: $readTimeoutMs,
            totalTimeoutMs: $totalTimeoutMs,
            cleanupMarginMs: $request->timeouts->cleanupMarginMs,
            maxAttempts: $maxAttempts,
            maxDistinctRoutes: $maxDistinctRoutes,
            maxRedirects: $maxRedirects,
            retryDelaysMs: $retryDelaysMs,
        );

        $allowDirectRoute = $rawPolicy->allowDirectRoute;

        $strategyKey = $isExplicit($rawPolicy, 'route.strategy')
            ? $rawPolicy->strategyKey
            : $profile->strategyKey;

        return new EffectivePolicy(
            tenantKey: $rawPolicy->tenantKey,
            subjectChain: $rawPolicy->subjectChain,
            targetId: $rawPolicy->targetId,
            destinationPolicyKey: $rawPolicy->destinationPolicyKey,
            trafficKey: $rawPolicy->trafficKey,
            routeProfileId: $profile->id,
            routeProfileKey: $profile->profileKey,
            routeProfileRevision: $profile->revision,
            allowedProfileKeys: $rawPolicy->allowedProfileKeys,
            timeouts: $effectiveTimeouts,
            retryDelaysDisabled: $rawPolicy->retryDelaysDisabled,
            allowDirectRoute: $allowDirectRoute,
            strategyKey: $strategyKey,
            outcomePolicyKey: $rawPolicy->outcomePolicyKey === 'default'
                ? ($request->outcomePolicyKey ?? 'default') : $rawPolicy->outcomePolicyKey,
            deniedRoutes: $rawPolicy->deniedRoutes,
            allowedDestinations: $rawPolicy->allowedDestinations,
            budgets: $rawPolicy->budgets,
            values: $rawPolicy->values,
            disabledSettings: $rawPolicy->disabledSettings,
            layerRevisions: $rawPolicy->layerRevisions,
            appliedLayers: $rawPolicy->appliedLayers,
            explanations: $rawPolicy->explanations,
            effectiveRevision: $rawPolicy->effectiveRevision,
            policyHash: $rawPolicy->policyHash,
            schemaVersion: $rawPolicy->schemaVersion,
            authorityVersions: $rawPolicy->authorityVersions,
        );
    }

    private function ensureOperationProvisioned(
        TenantKey $tenant,
        ExecutionContext $context,
        Uuid $profileId,
        Uuid $targetId,
        RequestTimeoutPolicy $timeouts,
    ): void {
        $tables = PackageSchema::tables();
        $exists = $this->scope->connection->table($tables['operations'])
            ->where('tenant_key', $tenant->value)
            ->where('id', $context->operation->operationId->value)
            ->exists();

        if (! $exists) {
            $this->operations->create($context->operation, $profileId, $targetId, $timeouts);
        }
    }

    private function resolveEndpoint(TenantKey $callerTenant, Route $route): ?Endpoint
    {
        if ($route->endpointId === null) {
            return null;
        }

        if ($route->tenantKey->equals($callerTenant)) {
            return $this->catalog->findEndpoint($callerTenant, $route->endpointId);
        }

        if (! $route->isAccessibleBy($callerTenant)) {
            throw new AuthorizationDenied;
        }

        $row = $this->scope->connection->table(PackageSchema::tables()['endpoints'])
            ->where('tenant_key', $route->tenantKey->value)
            ->where('id', $route->endpointId->value)
            ->first();

        return $row === null ? null : CatalogRowMapper::endpoint($row);
    }

    /**
     * @param  list<Uuid>  $triedRouteIds
     * @param  array<string, true>  $excludedRetryRouteIds
     * @return ?array{Route, RouteSelection}
     */
    private function findAlternateCandidate(
        SelectionResult $selectionResult,
        Uuid $profileId,
        array $triedRouteIds,
        array $excludedRetryRouteIds,
        int $maxDistinctRoutes,
    ): ?array {
        $triedMap = [];
        foreach ($triedRouteIds as $id) {
            $triedMap[$id->value] = true;
        }

        foreach ($selectionResult->snapshot->candidates as $candidate) {
            if (! $candidate->eligible() || $candidate->route === null) {
                continue;
            }
            $idStr = $candidate->routeId->value;
            if (isset($excludedRetryRouteIds[$idStr])) {
                continue;
            }
            if (! isset($triedMap[$idStr]) && count($triedMap) >= $maxDistinctRoutes) {
                continue;
            }
            $route = $candidate->route;

            return [
                $route,
                new RouteSelection(
                    $route->id,
                    $route->kind,
                    $profileId,
                    $route->endpointId,
                    $route->credentialVersion,
                    $route->egress,
                    $route->independenceGroup,
                ),
            ];
        }

        return null;
    }

    /**
     * @param  list<Uuid>  $attemptIds
     */
    private function mapUnselectedRoute(
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
    private function mapExhaustedRouteRotation(
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

        return $this->scope->connection->table(PackageSchema::tables()['route_health'])
            ->where(function ($query) use ($routesByOwner): void {
                foreach ($routesByOwner as $owner => $routeIds) {
                    $query->orWhere(fn ($owned) => $owned->where('tenant_key', $owner)->whereIn('route_id', $routeIds));
                }
            })
            ->where('state', 'open')
            ->where('reason_code', 'like', 'credential_hold:%')
            ->exists();
    }

    /**
     * @param  list<Uuid>  $attemptIds
     */
    private function mapDemandWait(
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
    private function mapAdmissionRejected(
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
    private function mapExhaustedRetries(
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

    private function buildAttemptFacts(
        AttemptResult $result,
        WireRequest $wireRequest,
        Route $route,
        EffectivePolicy $policy,
        int $hopAttempt,
        int $redirectHop,
    ): AttemptFacts {
        return AttemptFacts::fromAttempt(
            request: $wireRequest,
            result: $result,
            routeId: $route->id,
            routeKind: $route->kind,
            endpointId: $route->endpointId,
            egressKey: $route->egress->key,
            credentialVersion: $route->credentialVersion,
            attemptNumber: $hopAttempt,
            redirectHop: $redirectHop,
        );
    }

    private function resolveRedirectUrl(SafeUrl $baseUrl, string $location): SafeUrl
    {
        $trimmed = trim($location);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Redirect Location header must not be empty.');
        }

        $fragmentPos = strpos($trimmed, '#');
        if ($fragmentPos !== false) {
            $trimmed = substr($trimmed, 0, $fragmentPos);
        }

        if ($trimmed === '') {
            return $baseUrl;
        }

        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $trimmed) === 1) {
            return new SafeUrl($trimmed);
        }

        if (str_starts_with($trimmed, '//')) {
            return new SafeUrl($baseUrl->origin->scheme.':'.$trimmed);
        }

        $originPrefix = $baseUrl->origin->value();
        if (str_starts_with($trimmed, '/')) {
            return new SafeUrl($originPrefix.$trimmed);
        }

        $baseRevealed = $baseUrl->reveal();
        $basePath = parse_url($baseRevealed, PHP_URL_PATH);
        $basePath = is_string($basePath) && $basePath !== '' ? $basePath : '/';

        if (str_starts_with($trimmed, '?')) {
            return new SafeUrl($originPrefix.$basePath.$trimmed);
        }

        $lastSlash = strrpos($basePath, '/');
        $dir = $lastSlash === false ? '/' : substr($basePath, 0, $lastSlash + 1);

        return new SafeUrl($originPrefix.$this->normalizeRelativePath($dir.$trimmed));
    }

    private function normalizeRelativePath(string $path): string
    {
        $query = '';
        $qPos = strpos($path, '?');
        if ($qPos !== false) {
            $query = substr($path, $qPos);
            $path = substr($path, 0, $qPos);
        }

        $segments = explode('/', $path);
        $resolved = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($resolved);

                continue;
            }
            $resolved[] = $segment;
        }

        return '/'.implode('/', $resolved).$query;
    }

    private function resolveRetryDelayMs(
        EffectivePolicy $policy,
        ResolvedOutcome $outcome,
        int $hopAttempt,
    ): int {
        $retryAfter = $outcome->retryAfter();
        if ($retryAfter !== null) {
            $now = $this->nowWall();
            $diffUs = DatabaseClock::micros($retryAfter->value) - DatabaseClock::micros($now);

            return max(0, intdiv($diffUs + 999, 1000));
        }

        if ($policy->retryDelaysDisabled) {
            return 0;
        }

        $delays = $policy->timeouts->retryDelaysMs;
        if ($delays === []) {
            return 0;
        }

        $index = min(max(0, $hopAttempt - 1), count($delays) - 1);

        return max(0, $delays[$index]);
    }

    private function canFitWindowMs(ExecutionContext $context, int $requiredMs): bool
    {
        $required = new Duration(max(0, $requiredMs));
        if (! $context->canFitDuration($required)) {
            return false;
        }

        if ($this->nowMonotonicNs() + $required->nanos() > $context->monotonicDeadlineNs) {
            return false;
        }

        $nowWall = $this->nowWall();
        $remainingWallUs = DatabaseClock::micros($context->operation->deadlineAt) - DatabaseClock::micros($nowWall);

        return $remainingWallUs >= $requiredMs * 1000;
    }

    private function workBudget(ExecutionContext $context): WorkBudget
    {
        return new class($context, fn (ExecutionContext $c, int $ms): bool => $this->canFitWindowMs($c, $ms)) implements WorkBudget
        {
            /**
             * @param  Closure(ExecutionContext, int): bool  $checker
             */
            public function __construct(
                private readonly ExecutionContext $context,
                private readonly Closure $checker,
            ) {}

            public function canStart(Duration $required): bool
            {
                return ($this->checker)($this->context, $required->milliseconds);
            }
        };
    }

    private function safeCancelPermit(TenantKey $tenant, Uuid $operationId, Uuid $permitId): void
    {
        try {
            $this->reservations->cancel($tenant, $operationId, $permitId);
        } catch (Throwable) {
        }
    }

    private function closeArtifactIfNeeded(?ResponseDescriptor $previous, ?ResponseDescriptor $keep): void
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

    /**
     * @param  list<Uuid>  $items
     * @return list<Uuid>
     */
    private function appendUniqueUuid(array $items, Uuid $candidate): array
    {
        foreach ($items as $existing) {
            if ($existing->equals($candidate)) {
                return $items;
            }
        }

        $items[] = $candidate;

        return $items;
    }

    private function nowWall(): DateTimeImmutable
    {
        return DatabaseClock::now($this->scope->connection);
    }

    private function nowMonotonicNs(): int
    {
        return $this->monotonicClockNs !== null ? ($this->monotonicClockNs)() : hrtime(true);
    }

    private function sleep(int $microseconds): void
    {
        if ($microseconds <= 0) {
            return;
        }

        if ($this->sleepUs !== null) {
            ($this->sleepUs)($microseconds);

            return;
        }

        usleep($microseconds);
    }
}
