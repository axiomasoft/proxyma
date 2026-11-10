<?php

declare(strict_types=1);

require_once __DIR__.'/../Support/InMemoryTransactionCoordinator.php';

use Proxyma\Contracts\Admission;
use Proxyma\Contracts\LockedAdmissionGuard;
use Proxyma\Contracts\OwnerAuthority;
use Proxyma\Contracts\PhysicalTransport;
use Proxyma\Contracts\TransactionCoordinator;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Catalog\CredentialRef;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\Endpoint;
use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Catalog\RouteProfile;
use Proxyma\Domain\Catalog\SharedRouteGrant;
use Proxyma\Domain\Catalog\Target;
use Proxyma\Domain\Execution\AdmissionLockTier;
use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\AuthorizedWireRequest;
use Proxyma\Domain\Execution\AuthorizedWireSnapshot;
use Proxyma\Domain\Execution\BlockedReason;
use Proxyma\Domain\Execution\ClaimCandidate;
use Proxyma\Domain\Execution\ClaimDenialReason;
use Proxyma\Domain\Execution\ClaimDenied;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\DecodedTextBody;
use Proxyma\Domain\Execution\DemandPurpose;
use Proxyma\Domain\Execution\DemandState;
use Proxyma\Domain\Execution\DemandTicket;
use Proxyma\Domain\Execution\Duration;
use Proxyma\Domain\Execution\ExecutionBlocked;
use Proxyma\Domain\Execution\ExecutionContext;
use Proxyma\Domain\Execution\ExecutionDeferred;
use Proxyma\Domain\Execution\ExecutionSucceeded;
use Proxyma\Domain\Execution\ExecutionUnknownAfterAuthorization;
use Proxyma\Domain\Execution\IdempotencyClass;
use Proxyma\Domain\Execution\LateReceiptClaim;
use Proxyma\Domain\Execution\LateReceiptOutcome;
use Proxyma\Domain\Execution\LateReceiptTransition;
use Proxyma\Domain\Execution\LockedOwnerState;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\OperationCorrelation;
use Proxyma\Domain\Execution\OutboundRequest;
use Proxyma\Domain\Execution\OutcomeClassification;
use Proxyma\Domain\Execution\OutcomeDecision;
use Proxyma\Domain\Execution\OwnerRef;
use Proxyma\Domain\Execution\OwnerSnapshot;
use Proxyma\Domain\Execution\PermitEvidence;
use Proxyma\Domain\Execution\PermitState;
use Proxyma\Domain\Execution\PermitWait;
use Proxyma\Domain\Execution\PreparedHeaders;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\ResolvedSecret;
use Proxyma\Domain\Execution\ResolvedUrl;
use Proxyma\Domain\Execution\ResponseDescriptor;
use Proxyma\Domain\Execution\RetryAt;
use Proxyma\Domain\Execution\RouteSelection;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Execution\ScheduledPermit;
use Proxyma\Domain\Execution\TlsTrustConfiguration;
use Proxyma\Domain\Execution\TransportFailure;
use Proxyma\Domain\Execution\TransportMetrics;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireBody;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Health\HealthAttribution;
use Proxyma\Domain\Health\HealthEpochMap;
use Proxyma\Domain\Policy\BudgetConstraint;
use Proxyma\Domain\Policy\BudgetConstraintSet;
use Proxyma\Domain\Policy\RouteSubsetEnforcer;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Tests\Support\InMemoryTransactionCoordinator as DatabaseTransactionCoordinator;

it('validates Uuid, ContentHash, Duration, RetryAt, TenantKey, and SubjectChain with negative scenarios', function (): void {
    $id1 = Uuid::random();
    $id2 = Uuid::random();

    expect($id1->equals($id1))->toBeTrue()
        ->and($id1->equals($id2))->toBeFalse()
        ->and((string) $id1)->toBe($id1->value);

    foreach (['', 'not-a-uuid', '550E8400-E29B-41D4-A716-446655440000', "550e8400-e29b-41d4-a716-446655440000\n"] as $invalidUuid) {
        expect(static fn () => new Uuid($invalidUuid))->toThrow(InvalidArgumentException::class);
    }

    $hash = ContentHash::of('payload');
    expect($hash->equals(new ContentHash($hash->hex)))->toBeTrue();
    expect(static fn () => new ContentHash('ABC'))->toThrow(InvalidArgumentException::class);

    expect(Duration::seconds(5)->milliseconds)->toBe(5000)
        ->and(Duration::nanoseconds(2_500_000)->milliseconds)->toBe(2)
        ->and(Duration::seconds(1)->plus(Duration::milliseconds(250))->nanos())->toBe(1_250_000_000);
    expect(static fn () => new Duration(-1))->toThrow(InvalidArgumentException::class);

    $retryAt = new RetryAt(new DateTimeImmutable('2026-11-09T22:00:00+04:00'));
    expect($retryAt->value->getTimezone()->getName())->toBe('UTC');

    $systemTenant = TenantKey::system();
    expect($systemTenant->isSystem())->toBeTrue()
        ->and($systemTenant->value)->toBe('system');

    foreach (['', '*', 'UPPERCASE', 'tenant with space', "tenant\n"] as $badTenant) {
        expect(static fn () => new TenantKey($badTenant))->toThrow(InvalidArgumentException::class);
    }

    $tenant = new TenantKey('acme');
    $parents = [];
    for ($i = 1; $i <= 15; $i++) {
        $parents[] = new SubjectRef($tenant, "level_{$i}", "id_{$i}");
    }

    $leafWith15Parents = new SubjectRef($tenant, 'leaf', 'leaf_1', $parents);
    $chain = SubjectChain::fromSubject($leafWith15Parents);
    expect($chain->ordered)->toHaveCount(16)
        ->and($chain->ancestors())->toHaveCount(15);

    // 16th ancestor makes total chain depth 17 (> 16 max depth) -> rejected
    $sixteenParents = [...$parents, new SubjectRef($tenant, 'level_16', 'id_16')];
    expect(static fn () => new SubjectChain(new SubjectRef($tenant, 'leaf', 'leaf_1'), $sixteenParents))
        ->toThrow(InvalidArgumentException::class, '16');

    // Cycle in parent chain -> rejected
    expect(static fn () => new SubjectRef($tenant, 'crawler', 'c1', [
        new SubjectRef($tenant, 'platform', 'p1'),
        new SubjectRef($tenant, 'crawler', 'c1'),
    ]))->toThrow(InvalidArgumentException::class, 'Cycle');

    // Один тип может повторяться с разными ID; конфликт родителей проверяет resolver.
    expect((new SubjectRef($tenant, 'crawler', 'c1', [
        new SubjectRef($tenant, 'platform', 'p1'),
        new SubjectRef($tenant, 'platform', 'p2'),
    ]))->parents)->toHaveCount(2);

    // Cross-tenant parent -> rejected
    expect(static fn () => new SubjectRef($tenant, 'crawler', 'c1', [
        new SubjectRef(TenantKey::system(), 'platform', 'p1'),
    ]))->toThrow(InvalidArgumentException::class, 'Cross-tenant');
});

it('enforces Catalog identity invariants: CanonicalOrigin, Endpoint, Route XOR, EgressIdentity, RouteProfile empty/direct rules, and Target', function (): void {
    expect(CanonicalOrigin::fromUrl('HTTPS://ПРИМЕР.РФ/path')->value())->toBe('https://xn--e1afmkfd.xn--p1ai:443')
        ->and(CanonicalOrigin::fromUrl('https://[0:0:0:0:0:0:0:1]/a')->value())->toBe('https://[::1]:443')
        ->and(CanonicalOrigin::fromUrl('https://EXAMPLE.test.:443/x?q=1')->hash()->hex)
        ->toBe(CanonicalOrigin::fromUrl('https://example.test')->hash()->hex);

    expect(static fn () => CanonicalOrigin::fromUrl('https://user:pass@example.test'))->toThrow(InvalidArgumentException::class);
    expect(static fn () => CanonicalOrigin::fromUrl('ftp://example.test'))->toThrow(InvalidArgumentException::class);

    $tenant = new TenantKey('tenant-a');
    $otherTenant = new TenantKey('tenant-b');
    $endpointId = Uuid::random();

    $endpoint = new Endpoint(
        id: $endpointId,
        tenantKey: $tenant,
        protocol: ProxyProtocol::Http,
        host: 'PROXY.EXAMPLE.TEST.',
        port: 8080,
        credential: new CredentialRef('vault:proxy-1', 2),
        revision: 1,
    );
    expect($endpoint->host)->toBe('proxy.example.test')
        ->and($endpoint->credentialVersion())->toBe(2);

    // EgressIdentity cannot be a random UUID; conservativePool groups deterministically
    expect(static fn () => new EgressIdentity(Uuid::random()->value))->toThrow(InvalidArgumentException::class, 'UUID');
    $poolEgress = EgressIdentity::conservativePool('provider-pool-eu');
    expect($poolEgress->key)->toBe('pool:provider-pool-eu')
        ->and($poolEgress->conservativePoolFallback)->toBeTrue();

    // Route XOR: Direct with endpointId is rejected; Proxy without endpointId is rejected
    expect(static fn () => new Route(
        id: Uuid::random(),
        tenantKey: $tenant,
        routeKey: 'invalid-direct',
        kind: RouteKind::Direct,
        endpointId: $endpointId,
        enabled: true,
        egress: EgressIdentity::explicit('nat:worker-1'),
    ))->toThrow(InvalidArgumentException::class, 'XOR');

    expect(static fn () => new Route(
        id: Uuid::random(),
        tenantKey: $tenant,
        routeKey: 'invalid-proxy',
        kind: RouteKind::Proxy,
        endpointId: null,
        enabled: true,
        egress: $poolEgress,
    ))->toThrow(InvalidArgumentException::class, 'XOR');

    // Egress key cannot equal routeKey or independenceGroup
    expect(static fn () => new Route(
        id: Uuid::random(),
        tenantKey: $tenant,
        routeKey: 'proxy-1',
        kind: RouteKind::Proxy,
        endpointId: $endpointId,
        enabled: true,
        egress: EgressIdentity::explicit('proxy-1'),
    ))->toThrow(InvalidArgumentException::class, 'Egress identity');

    $proxyRoute = new Route(
        id: Uuid::random(),
        tenantKey: $tenant,
        routeKey: 'proxy-1',
        kind: RouteKind::Proxy,
        endpointId: $endpointId,
        enabled: true,
        egress: $poolEgress,
        independenceGroup: 'dc-1',
        credentialVersion: 2,
        revision: 1,
        sharedGrant: false,
    );

    $sharedRouteId = Uuid::random();
    $sharedProxyRoute = new Route(
        id: $sharedRouteId,
        tenantKey: $otherTenant,
        routeKey: 'shared-proxy',
        kind: RouteKind::Proxy,
        endpointId: Uuid::random(),
        enabled: true,
        egress: $poolEgress,
        sharedGrant: true,
        accessGrant: new SharedRouteGrant(Uuid::random(), $otherTenant, $tenant, $sharedRouteId),
    );

    $unsharedForeignRoute = new Route(
        id: Uuid::random(),
        tenantKey: $otherTenant,
        routeKey: 'private-foreign-proxy',
        kind: RouteKind::Proxy,
        endpointId: Uuid::random(),
        enabled: true,
        egress: $poolEgress,
        sharedGrant: false,
    );

    $directRoute = new Route(
        id: Uuid::random(),
        tenantKey: $tenant,
        routeKey: 'direct-main',
        kind: RouteKind::Direct,
        endpointId: null,
        enabled: true,
        egress: EgressIdentity::explicit('nat:main'),
    );

    // Empty profile always denies
    $emptyProfile = new RouteProfile(Uuid::random(), $tenant, 'empty-profile', []);
    expect($emptyProfile->allowsRoute($proxyRoute, directAllowedByPolicy: true))->toBeFalse()
        ->and($emptyProfile->allowsRoute($directRoute, directAllowedByPolicy: true))->toBeFalse();

    // Populated profile enforces exact membership, tenant sharing, and explicit direct policy gate
    $profile = new RouteProfile(Uuid::random(), $tenant, 'crawler-profile', [
        $proxyRoute->id,
        $sharedProxyRoute->id,
        $unsharedForeignRoute->id,
        $directRoute->id,
    ]);

    expect($profile->allowsRoute($proxyRoute))->toBeTrue()
        ->and($profile->allowsRoute($sharedProxyRoute))->toBeTrue()
        ->and($profile->allowsRoute($unsharedForeignRoute))->toBeFalse()
        ->and($profile->allowsRoute($directRoute, directAllowedByPolicy: false))->toBeFalse()
        ->and($profile->allowsRoute($directRoute, directAllowedByPolicy: true))->toBeTrue();

    $target = new Target(
        id: Uuid::random(),
        tenantKey: $tenant,
        targetKey: 'procurement.detail',
        origin: CanonicalOrigin::fromUrl('https://example.test'),
        destinationPolicyKey: 'strict-default',
    );
    expect($target->isRetired())->toBeFalse();

    expect(static fn () => new CatalogMutationActor('', 'reason'))->toThrow(InvalidArgumentException::class);
    expect(static fn () => new CatalogMutationActor('operator', ''))->toThrow(InvalidArgumentException::class);
});

it('enforces DemandState, PermitState, legacy released mapping, and LateReceiptTransition invariants', function (): void {
    // DemandState lifecycle
    expect(DemandState::Active->canTransitionTo(DemandState::Admitted))->toBeTrue()
        ->and(DemandState::Active->canTransitionTo(DemandState::Cancelled))->toBeTrue()
        ->and(DemandState::Active->canTransitionTo(DemandState::Expired))->toBeTrue()
        ->and(DemandState::Active->canTransitionTo(DemandState::Completed))->toBeFalse()
        ->and(DemandState::Admitted->canTransitionTo(DemandState::Completed))->toBeTrue()
        ->and(DemandState::Completed->canTransitionTo(DemandState::Active))->toBeFalse()
        ->and(DemandState::fromLegacyState('queued'))->toBe(DemandState::Active)
        ->and(DemandState::fromLegacyState('granted', false))->toBe(DemandState::Admitted)
        ->and(DemandState::fromLegacyState('granted', true))->toBe(DemandState::Completed);

    // DemandTicket deadline invariant and fair FIFO tie-break
    $now = new DateTimeImmutable('2026-11-09T18:00:00Z');
    expect(static fn () => new DemandTicket(
        demandId: Uuid::random(),
        operationId: Uuid::random(),
        physicalAttemptId: Uuid::random(),
        ticketSequence: 1,
        limitingScopeKey: 'origin:abc',
        expiresAt: $now->modify('+30 seconds'),
        operationDeadlineAt: $now->modify('+10 seconds'),
    ))->toThrow(InvalidArgumentException::class, 'deadline');

    $ticketA = new DemandTicket(
        demandId: new Uuid('00000000-0000-4000-8000-000000000001'),
        operationId: Uuid::random(),
        physicalAttemptId: Uuid::random(),
        ticketSequence: 5,
        limitingScopeKey: 'origin:abc',
        expiresAt: $now->modify('+10 seconds'),
        operationDeadlineAt: $now->modify('+30 seconds'),
    );
    $ticketB = new DemandTicket(
        demandId: new Uuid('00000000-0000-4000-8000-000000000002'),
        operationId: Uuid::random(),
        physicalAttemptId: Uuid::random(),
        ticketSequence: 5,
        limitingScopeKey: 'origin:abc',
        expiresAt: $now->modify('+10 seconds'),
        operationDeadlineAt: $now->modify('+30 seconds'),
    );
    expect($ticketA->comparePriority($ticketB))->toBeLessThan(0);

    // PermitState transitions: never reserved or *_before_send after authorized
    expect(PermitState::Reserved->canTransitionTo(PermitState::Authorized))->toBeTrue()
        ->and(PermitState::Reserved->canTransitionTo(PermitState::CancelledBeforeSend))->toBeTrue()
        ->and(PermitState::Reserved->canTransitionTo(PermitState::ExpiredBeforeSend))->toBeTrue()
        ->and(PermitState::Reserved->canTransitionTo(PermitState::Completed))->toBeFalse()
        ->and(PermitState::Authorized->canTransitionTo(PermitState::Completed))->toBeTrue()
        ->and(PermitState::Authorized->canTransitionTo(PermitState::UnknownAfterAuthorization))->toBeTrue()
        ->and(PermitState::Authorized->canTransitionTo(PermitState::Reserved))->toBeFalse()
        ->and(PermitState::Authorized->canTransitionTo(PermitState::CancelledBeforeSend))->toBeFalse()
        ->and(PermitState::Authorized->canTransitionTo(PermitState::ExpiredBeforeSend))->toBeFalse()
        ->and(PermitState::UnknownAfterAuthorization->canTransitionTo(PermitState::Completed))->toBeTrue()
        ->and(PermitState::UnknownAfterAuthorization->canTransitionTo(PermitState::CancelledBeforeSend))->toBeFalse();

    // Quota vs inflight accounting semantics
    expect(PermitState::Reserved->consumesQuotaPermanently())->toBeFalse()
        ->and(PermitState::CancelledBeforeSend->consumesQuotaPermanently())->toBeFalse()
        ->and(PermitState::ExpiredBeforeSend->consumesQuotaPermanently())->toBeFalse()
        ->and(PermitState::Authorized->consumesQuotaPermanently())->toBeTrue()
        ->and(PermitState::UnknownAfterAuthorization->consumesQuotaPermanently())->toBeTrue()
        ->and(PermitState::Completed->consumesQuotaPermanently())->toBeTrue()
        ->and(PermitState::Reserved->holdsInflightCapacity())->toBeTrue()
        ->and(PermitState::Authorized->holdsInflightCapacity())->toBeTrue()
        ->and(PermitState::UnknownAfterAuthorization->holdsInflightCapacity())->toBeFalse()
        ->and(PermitState::Completed->holdsInflightCapacity())->toBeFalse();

    // Legacy 'released' mapping: never blindly renamed to 'completed' without send/receipt facts
    expect(PermitState::fromLegacyStatus('released', wasAuthorized: false))->toBe(PermitState::CancelledBeforeSend)
        ->and(PermitState::fromLegacyStatus('released', wasAuthorized: true, hasVerifiedReceipt: false))->toBe(PermitState::UnknownAfterAuthorization)
        ->and(PermitState::fromLegacyStatus('released', wasAuthorized: true, hasVerifiedReceipt: null))->toBe(PermitState::UnknownAfterAuthorization)
        ->and(PermitState::fromLegacyStatus('released', wasAuthorized: true, hasVerifiedReceipt: true))->toBe(PermitState::Completed)
        ->and(PermitState::fromLegacyStatus('expired', wasAuthorized: false))->toBe(PermitState::ExpiredBeforeSend)
        ->and(PermitState::fromLegacyStatus('expired', wasAuthorized: true, hasVerifiedReceipt: false))->toBe(PermitState::UnknownAfterAuthorization);

    expect(static fn () => PermitState::fromLegacyStatus('reserved', wasAuthorized: true))->toThrow(InvalidArgumentException::class);
    expect(static fn () => PermitState::fromLegacyStatus('sent', wasAuthorized: false))->toThrow(InvalidArgumentException::class);

    // Late receipt transition: unknown_after_authorization -> completed
    $opId = Uuid::random();
    $attemptId = Uuid::random();
    $wireFp = ContentHash::of('wire-fp-1');
    $receiptDigest = ContentHash::of('receipt-1');
    $epochsAtAuth = new HealthEpochMap(['route:1' => 3, 'target:1' => 1]);
    $claim = new LateReceiptClaim($opId, $attemptId, $wireFp, 'rcpt-1', $receiptDigest, $epochsAtAuth);

    $firstTransition = LateReceiptTransition::evaluate(
        currentState: PermitState::UnknownAfterAuthorization,
        expectedAttemptId: $attemptId,
        expectedWireFingerprint: $wireFp,
        incoming: $claim,
        liveHealthEpochs: $epochsAtAuth,
    );
    expect($firstTransition->outcome)->toBe(LateReceiptOutcome::TransitionedToCompleted)
        ->and($firstTransition->permitState)->toBe(PermitState::Completed)
        ->and($firstTransition->receiptPersisted)->toBeTrue()
        ->and($firstTransition->healthReducerAllowed)->toBeTrue()
        ->and($firstTransition->quotaConsumedUnchanged)->toBeTrue()
        ->and($firstTransition->inflightRemainsReleased)->toBeTrue();

    // Duplicate same receipt ID + digest is idempotent
    $duplicateTransition = LateReceiptTransition::evaluate(
        currentState: PermitState::Completed,
        expectedAttemptId: $attemptId,
        expectedWireFingerprint: $wireFp,
        incoming: $claim,
        liveHealthEpochs: $epochsAtAuth,
        existingReceiptId: 'rcpt-1',
        existingReceiptDigest: $receiptDigest,
    );
    expect($duplicateTransition->outcome)->toBe(LateReceiptOutcome::IdempotentDuplicate)
        ->and($duplicateTransition->permitState)->toBe(PermitState::Completed);

    // Conflicting receipt digest -> conflict
    $conflictingClaim = new LateReceiptClaim($opId, $attemptId, $wireFp, 'rcpt-1', ContentHash::of('different'), $epochsAtAuth);
    $conflictTransition = LateReceiptTransition::evaluate(
        currentState: PermitState::Completed,
        expectedAttemptId: $attemptId,
        expectedWireFingerprint: $wireFp,
        incoming: $conflictingClaim,
        liveHealthEpochs: $epochsAtAuth,
        existingReceiptId: 'rcpt-1',
        existingReceiptDigest: $receiptDigest,
    );
    expect($conflictTransition->outcome)->toBe(LateReceiptOutcome::ConflictingReceipt)
        ->and($conflictTransition->receiptPersisted)->toBeFalse();

    // Stale health epoch forbids reducer update but still persists receipt and completes permit
    $staleEpochTransition = LateReceiptTransition::evaluate(
        currentState: PermitState::UnknownAfterAuthorization,
        expectedAttemptId: $attemptId,
        expectedWireFingerprint: $wireFp,
        incoming: $claim,
        liveHealthEpochs: new HealthEpochMap(['route:1' => 4, 'target:1' => 1]),
    );
    expect($staleEpochTransition->outcome)->toBe(LateReceiptOutcome::TransitionedToCompleted)
        ->and($staleEpochTransition->permitState)->toBe(PermitState::Completed)
        ->and($staleEpochTransition->receiptPersisted)->toBeTrue()
        ->and($staleEpochTransition->healthReducerAllowed)->toBeFalse();
});

it('seals headers and body into WireRequest fingerprint, prevents secret serialization, and separates delivery from OutcomeClassification', function (): void {
    $url = new SafeUrl('https://example.test/api/items?signature=secret-token');
    expect($url->template())->toBe('https://example.test:443/api/items')
        ->and($url->reveal())->toContain('signature=secret-token')
        ->and($url->__debugInfo())->not->toHaveKey('value')
        ->and(json_encode($url->__debugInfo()))->not->toContain('secret-token');
    expect(static fn () => serialize($url))->toThrow(LogicException::class);

    $resolvedUrl = new ResolvedUrl($url, '192.0.2.10', ['192.0.2.10', '192.0.2.11']);
    expect($resolvedUrl->withAddress('192.0.2.11')->address)->toBe('192.0.2.11');
    expect(static fn () => $resolvedUrl->withAddress('198.51.100.1'))->toThrow(InvalidArgumentException::class);

    $headersA = new PreparedHeaders(['Authorization' => 'Bearer secret-a', 'User-Agent' => 'Proxyma/0.1']);
    $headersB = new PreparedHeaders(['Authorization' => 'Bearer secret-b', 'User-Agent' => 'Proxyma/0.1']);
    expect($headersA->digest->equals($headersB->digest))->toBeFalse()
        ->and($headersA->acrossOrigin()->names())->toBe(['user-agent'])
        ->and(json_encode($headersA->__debugInfo()))->not->toContain('secret-a');
    expect(static fn () => serialize($headersA))->toThrow(LogicException::class);
    expect(static fn () => new PreparedHeaders(['Proxy-Authorization' => 'Basic xyz']))->toThrow(InvalidArgumentException::class);

    $body = WireBody::fromString('{"query":"value"}');
    expect(WireBody::fromVerified('{"query":"value"}', $body->hash, $body->length)->length)->toBe($body->length);
    expect(static fn () => WireBody::fromVerified('{"tampered":true}', $body->hash))->toThrow(InvalidArgumentException::class);
    expect(static fn () => serialize($body))->toThrow(LogicException::class);

    $timeouts = new RequestTimeoutPolicy(connectTimeoutMs: 1000, readTimeoutMs: 2000, totalTimeoutMs: 5000, maxAttempts: 3, maxDistinctRoutes: 2, maxRedirects: 1);
    $opId = Uuid::random();
    $attemptId = Uuid::random();
    $targetId = Uuid::random();
    $deadline = new DateTimeImmutable('2026-11-09T19:00:00Z');

    // AmbiguousWrite POST never retries automatically even when policy maxAttempts is 3
    $ambiguousPost = new OutboundRequest(
        operationId: $opId,
        targetId: $targetId,
        method: 'POST',
        url: $url,
        timeouts: $timeouts,
        idempotency: IdempotencyClass::AmbiguousWrite,
        headers: $headersA,
        body: $body,
    );
    expect($ambiguousPost->maxAutomaticAttempts())->toBe(1);
    expect(static fn () => new OutboundRequest($opId, $targetId, 'GET', $url, $timeouts, IdempotencyClass::ReadOnly, $headersA, $body))
        ->toThrow(InvalidArgumentException::class);

    $wireA = new WireRequest($opId, $attemptId, $targetId, 1, 'POST', $url, $timeouts, $deadline, $headersA, $body, IdempotencyClass::AmbiguousWrite);
    $wireB = new WireRequest($opId, $attemptId, $targetId, 1, 'POST', $url, $timeouts, $deadline, $headersB, $body, IdempotencyClass::AmbiguousWrite);
    expect($wireA->fingerprint()->equals($wireB->fingerprint()))->toBeFalse();

    // HTTP 404 is a physically delivered response; OutcomeClassification separates business meaning from delivery fact
    $notFoundDescriptor = new ResponseDescriptor(
        statusCode: 404,
        headers: ['Content-Type' => ['application/json']],
        body: new DecodedTextBody('{"error":"not_found"}'),
        wireBytes: 21,
        decodedBytes: 21,
    );
    $attemptResult = AttemptResult::delivered($notFoundDescriptor, new TransportMetrics(totalMs: 42));
    $classification = new OutcomeClassification(
        decision: OutcomeDecision::Accept,
        businessMeaning: 'resource_missing',
        healthAttribution: HealthAttribution::RouteSuccess,
    );
    $succeeded = new ExecutionSucceeded($notFoundDescriptor, [$attemptId], $classification);

    expect($attemptResult->wasDelivered())->toBeTrue()
        ->and($succeeded->response->statusCode)->toBe(404)
        ->and($succeeded->outcome?->businessMeaning)->toBe('resource_missing')
        ->and($succeeded->finalAttemptId->equals($attemptId))->toBeTrue();

    // ExecutionBlocked rejects secret keys in safeDetails
    expect(static fn () => new ExecutionBlocked(BlockedReason::CredentialHold, ['proxy_password' => 'leaked']))
        ->toThrow(InvalidArgumentException::class, 'secret');

    $unknown = new ExecutionUnknownAfterAuthorization($opId, $attemptId, Uuid::random(), $wireA->fingerprint(), TransportFailure::Timeout);
    expect($unknown->failure)->toBe(TransportFailure::Timeout);

    $secret = new ResolvedSecret(new CredentialRef('vault:cred', 1), 'user1', 'top-secret');
    expect(json_encode($secret->__debugInfo()))->not->toContain('top-secret');
    expect(static fn () => serialize($secret))->toThrow(LogicException::class);
    expect(static fn () => new TlsTrustConfiguration(verifyPeer: false))->toThrow(InvalidArgumentException::class);
});

it('enforces bounded extension contracts: RouteSubsetEnforcer, BudgetConstraintSet, and LockedAdmissionGuard', function (): void {
    $tenant = TenantKey::system();
    $egress = EgressIdentity::explicit('nat:default');
    $r1 = new Route(Uuid::random(), $tenant, 'route-1', RouteKind::Proxy, Uuid::random(), true, $egress);
    $r2 = new Route(Uuid::random(), $tenant, 'route-2', RouteKind::Proxy, Uuid::random(), true, $egress);
    $foreignRoute = new Route(Uuid::random(), $tenant, 'route-foreign', RouteKind::Proxy, Uuid::random(), true, $egress);

    expect(RouteSubsetEnforcer::enforceSubset([$r1, $r2], [$r2]))->toBe([$r2]);
    expect(static fn () => RouteSubsetEnforcer::enforceSubset([$r1, $r2], [$r1, $foreignRoute]))
        ->toThrow(InvalidArgumentException::class, 'outside the profile');

    $mandatoryOrigin = new BudgetConstraint('origin:1', emissionIntervalUs: 500_000, burst: 2, maxInflight: 4, mandatory: true);
    $contributedLoose = new BudgetConstraint('origin:1', emissionIntervalUs: 100_000, burst: 10, maxInflight: 20);
    $contributedStrict = new BudgetConstraint('origin:1', emissionIntervalUs: 1_000_000, burst: 1, maxInflight: 2);
    $contributedCustom = new BudgetConstraint('custom:tenant:acme', emissionIntervalUs: 250_000, burst: 1, maxInflight: 1);

    $merged = BudgetConstraintSet::mergeMandatoryWithContributed([$mandatoryOrigin], [$contributedLoose, $contributedStrict, $contributedCustom]);
    expect($merged->orderedScopeKeys())->toBe(['custom:tenant:acme', 'origin:1'])
        ->and($merged->byScopeKey['origin:1']->mandatory)->toBeTrue()
        ->and($merged->byScopeKey['origin:1']->emissionIntervalUs)->toBe(1_000_000)
        ->and($merged->byScopeKey['origin:1']->burst)->toBe(1)
        ->and($merged->byScopeKey['origin:1']->maxInflight)->toBe(2);

    $guard = new class implements LockedAdmissionGuard
    {
        public function tier(): AdmissionLockTier
        {
            return AdmissionLockTier::Budgets;
        }

        public function evaluateLocked(WireRequest $request, LockedOwnerState $owner, LockedTransactionContext $transaction): ?ClaimDenied
        {
            return new ClaimDenied(ClaimDenialReason::GuardDenied, ['tier' => $this->tier()->value]);
        }
    };

    $dummyOwner = new OwnerRef($tenant, 'standalone', 'op-1', 'tok-1', 1);
    $lockedOwner = new LockedOwnerState($dummyOwner, DemandPurpose::Ordinary, new DateTimeImmutable('+1 minute'));
    $txContext = new LockedTransactionContext('pgsql', 'tx-1', hrtime(true));
    $wire = new WireRequest(
        Uuid::random(),
        Uuid::random(),
        Uuid::random(),
        1,
        'GET',
        new SafeUrl('https://example.test/'),
        new RequestTimeoutPolicy(1000, 1000, 2000),
        new DateTimeImmutable('+1 minute'),
    );

    $denial = $guard->evaluateLocked($wire, $lockedOwner, $txContext);
    expect($denial?->reason)->toBe(ClaimDenialReason::GuardDenied);
});

it('proves OwnerAuthority, TransactionCoordinator, CommitLatch, and joint claim for standalone and process-like adapters without App', function (): void {
    $currentTxLevel = 0;
    $currentMonotonicNs = hrtime(true);

    // Transactional state store supporting outer commit/rollback and nested savepoints
    $stateStack = [[
        'owners' => [],
        'financial_claims' => [],
        'permits' => [],
        'sent_requests' => 0,
    ]];

    $readState = static function () use (&$stateStack): array {
        return $stateStack[array_key_last($stateStack)];
    };

    $writeState = static function (array $next) use (&$stateStack): void {
        $stateStack[array_key_last($stateStack)] = $next;
    };

    $coordinator = new DatabaseTransactionCoordinator(
        connectionName: 'pgsql',
        customTransactionDriver: static function (Closure $callback) use (&$currentTxLevel, &$stateStack) {
            $currentTxLevel++;
            $stateStack[] = $stateStack[array_key_last($stateStack)];
            try {
                $result = $callback();
                $committedFrame = array_pop($stateStack);
                $stateStack[array_key_last($stateStack)] = $committedFrame;
                $currentTxLevel--;

                return $result;
            } catch (Throwable $e) {
                array_pop($stateStack);
                $currentTxLevel--;
                throw $e;
            }
        },
        customLevelResolver: static function () use (&$currentTxLevel): int {
            return $currentTxLevel;
        },
        monotonicClock: static function () use (&$currentMonotonicNs): int {
            return $currentMonotonicNs;
        },
    );

    // Fake OwnerAuthority covering both standalone operations (with probe tokens & controls)
    // and process-like work attempts (with input hash/revision, subject generations, and handler health)
    $ownerAuthority = new class($readState) implements OwnerAuthority
    {
        public function __construct(private readonly Closure $readState, private readonly string $connection = 'pgsql') {}

        public function connectionName(): string
        {
            return $this->connection;
        }

        public function snapshot(OwnerRef $owner): ?OwnerSnapshot
        {
            $row = ($this->readState)()['owners'][$owner->ownerId] ?? null;
            if ($row === null || $row['owner_type'] !== $owner->ownerType || ! $owner->tenantKey->equals($row['tenant'])) {
                return null;
            }

            return new OwnerSnapshot(
                owner: $owner,
                deadlineAt: $row['deadline_at'],
                inputRevision: $row['input_revision'],
                inputHash: $row['input_hash'],
                controlVersions: $row['control_versions'],
                subjectGenerations: $row['subject_generations'],
                parentAttemptId: $row['parent_attempt_id'],
                adapterHealthId: $row['adapter_health_id'],
                adapterHealthEpoch: $row['adapter_health_epoch'],
                purpose: $row['purpose'],
                probeToken: $row['probe_token'],
            );
        }

        public function validateSnapshot(OwnerRef $owner, OwnerSnapshot $snapshot): bool
        {
            return $owner->matchesIdentityAndToken($snapshot->owner);
        }

        public function lockAndValidate(
            OwnerRef $owner,
            OwnerSnapshot $snapshot,
            LockedTransactionContext $transaction,
        ): LockedOwnerState|ExecutionBlocked {
            $transaction->assertConnection($this->connection);
            if (! $this->validateSnapshot($owner, $snapshot)) {
                return new ExecutionBlocked(BlockedReason::OwnerLost);
            }

            $row = ($this->readState)()['owners'][$owner->ownerId] ?? null;
            if ($row === null || ! $row['live'] || $row['generation'] !== $owner->generation || $row['token'] !== $owner->token()) {
                return new ExecutionBlocked(BlockedReason::OwnerLost);
            }

            if ($row['paused']) {
                return new ExecutionBlocked(BlockedReason::OwnerPaused);
            }

            if (! $row['handler_healthy']) {
                return new ExecutionBlocked(BlockedReason::ConfigurationHold);
            }

            if (
                $row['input_revision'] !== $snapshot->inputRevision
                || ($row['input_hash']?->hex !== $snapshot->inputHash?->hex)
                || $row['control_versions'] !== $snapshot->controlVersions
                || $row['subject_generations'] !== $snapshot->subjectGenerations
            ) {
                return new ExecutionBlocked(BlockedReason::OwnerLost);
            }

            $pinned = [];
            foreach ($row['control_versions'] as $k => $v) {
                $pinned['control:'.$k] = $v;
            }
            foreach ($row['subject_generations'] as $k => $v) {
                $pinned['subject:'.$k] = $v;
            }

            return new LockedOwnerState(
                owner: $owner,
                purpose: $row['purpose'],
                deadlineAt: $row['deadline_at'],
                pinnedVersions: $pinned,
                probeToken: $row['probe_token'],
                adapterHealthId: $row['adapter_health_id'],
                adapterHealthEpoch: $row['adapter_health_epoch'],
            );
        }
    };

    // Fake Admission service enforcing idempotent reserve, fingerprint conflict check, and locked claim
    $admission = new class($coordinator, $readState, $writeState) implements Admission
    {
        public function __construct(
            private readonly TransactionCoordinator $coordinator,
            private readonly Closure $readState,
            private readonly Closure $writeState,
        ) {}

        public function reserve(
            WireRequest $request,
            ExecutionContext $context,
            RouteSelection $route,
        ): ScheduledPermit|PermitWait|ExecutionDeferred|ExecutionBlocked {
            return $this->coordinator->run(function () use ($request): ScheduledPermit|ExecutionBlocked {
                $state = ($this->readState)();
                $key = $request->operationId->value.':'.$request->physicalAttemptId->value;
                if (isset($state['permits'][$key])) {
                    $existing = $state['permits'][$key]['permit'];
                    if (! $existing->evidence->wireFingerprint->equals($request->fingerprint())) {
                        return new ExecutionBlocked(BlockedReason::WireFingerprintConflict);
                    }

                    return $existing;
                }

                $now = new DateTimeImmutable('2026-11-09T18:00:00Z');
                $ticket = new DemandTicket(
                    demandId: Uuid::random(),
                    operationId: $request->operationId,
                    physicalAttemptId: $request->physicalAttemptId,
                    ticketSequence: count($state['permits']) + 1,
                    limitingScopeKey: $request->url->origin->budgetScopeKey(),
                    expiresAt: $now->modify('+30 seconds'),
                    operationDeadlineAt: $request->deadlineAt,
                    state: DemandState::Admitted,
                );
                $permit = new ScheduledPermit(
                    permitId: Uuid::random(),
                    ticket: $ticket,
                    slotAt: $now,
                    expiresAt: $now->modify('+60 seconds'),
                    evidence: new PermitEvidence(
                        policyRevisions: ['policy' => $request->policyRevision, 'route:'.$routeId = $request->targetId->value => 1],
                        healthEpochs: new HealthEpochMap(['route' => 1, 'target' => 1]),
                        wireFingerprint: $request->fingerprint(),
                    ),
                );
                $state['permits'][$key] = [
                    'permit' => $permit,
                    'state' => PermitState::Reserved,
                    'policy_revision' => $request->policyRevision,
                ];
                ($this->writeState)($state);

                return $permit;
            });
        }

        public function claimSendLocked(
            ScheduledPermit $permit,
            WireRequest $request,
            LockedOwnerState $ownerState,
            LockedTransactionContext $transaction,
        ): ClaimCandidate|PermitWait|ClaimDenied|ExecutionDeferred|ExecutionBlocked {
            $transaction->assertConnection($this->coordinator->connectionName());
            $state = ($this->readState)();
            $key = $request->operationId->value.':'.$request->physicalAttemptId->value;
            $record = $state['permits'][$key] ?? null;

            if ($record === null) {
                return new ClaimDenied(ClaimDenialReason::OwnerLost);
            }

            if (! $record['permit']->evidence->wireFingerprint->equals($request->fingerprint())) {
                return new ClaimDenied(ClaimDenialReason::WireFingerprintConflict);
            }

            if ($record['policy_revision'] !== $request->policyRevision) {
                return new ClaimDenied(ClaimDenialReason::PolicyDrift);
            }

            if ($record['state'] === PermitState::Authorized || $record['state'] === PermitState::Completed) {
                return new ClaimDenied(ClaimDenialReason::AlreadyClaimed);
            }

            $authorizedAt = new DateTimeImmutable('2026-11-09T18:00:01Z');
            $expiresAt = new DateTimeImmutable('2026-11-09T18:01:01Z'); // 60s window
            $state['permits'][$key]['state'] = PermitState::Authorized;
            ($this->writeState)($state);

            return $this->coordinator->issueCandidate(
                permitId: $permit->permitId,
                operationId: $request->operationId,
                physicalAttemptId: $request->physicalAttemptId,
                wireFingerprint: $request->fingerprint(),
                authorizedAt: $authorizedAt,
                expiresAt: $expiresAt,
                context: $transaction,
            );
        }

        public function abandon(DemandTicket $ticket, ExecutionContext $context): void
        {
            $this->coordinator->run(function () use ($ticket): void {
                $state = ($this->readState)();
                $key = $ticket->operationId->value.':'.$ticket->physicalAttemptId->value;
                if (isset($state['permits'][$key]) && $state['permits'][$key]['state'] === PermitState::Reserved) {
                    $state['permits'][$key]['state'] = PermitState::CancelledBeforeSend;
                    ($this->writeState)($state);
                }
            });
        }
    };

    $tenant = TenantKey::system();
    $deadline = new DateTimeImmutable('2026-11-09T18:05:00Z');
    $probeToken = Uuid::random();

    // 1. Standalone probe owner scenario
    $standaloneOwner = new OwnerRef($tenant, 'outbound_operation', 'op-standalone-1', 'secret-token-1', 1);
    $currentState = $readState();
    $currentState['owners']['op-standalone-1'] = [
        'tenant' => $tenant,
        'owner_type' => 'outbound_operation',
        'generation' => 1,
        'token' => 'secret-token-1',
        'live' => true,
        'paused' => false,
        'handler_healthy' => true,
        'deadline_at' => $deadline,
        'input_revision' => 1,
        'input_hash' => null,
        'control_versions' => ['system:outbound' => 1],
        'subject_generations' => [],
        'parent_attempt_id' => null,
        'adapter_health_id' => null,
        'adapter_health_epoch' => null,
        'purpose' => DemandPurpose::Probe,
        'probe_token' => $probeToken,
    ];

    // 2. Process-like work attempt owner scenario (joint financial + network claim)
    $processOwner = new OwnerRef($tenant, 'process_attempt', 'work-attempt-42', 'lease-token-42', 3);
    $adapterHealthId = Uuid::random();
    $currentState['owners']['work-attempt-42'] = [
        'tenant' => $tenant,
        'owner_type' => 'process_attempt',
        'generation' => 3,
        'token' => 'lease-token-42',
        'live' => true,
        'paused' => false,
        'handler_healthy' => true,
        'deadline_at' => $deadline,
        'input_revision' => 7,
        'input_hash' => ContentHash::of('work-input-v7'),
        'control_versions' => ['documents' => 2, 'provider:llm' => 4],
        'subject_generations' => ['document:99' => 5],
        'parent_attempt_id' => null,
        'adapter_health_id' => $adapterHealthId,
        'adapter_health_epoch' => 2,
        'purpose' => DemandPurpose::Ordinary,
        'probe_token' => null,
    ];
    $writeState($currentState);

    // Verify standalone probe owner snapshot & lock
    $standaloneSnap = $ownerAuthority->snapshot($standaloneOwner);
    expect($standaloneSnap)->not->toBeNull();
    $lockedStandalone = $coordinator->run(static fn (LockedTransactionContext $tx) => $ownerAuthority->lockAndValidate($standaloneOwner, $standaloneSnap, $tx));
    expect($lockedStandalone)->toBeInstanceOf(LockedOwnerState::class)
        ->and($lockedStandalone->purpose)->toBe(DemandPurpose::Probe)
        ->and($lockedStandalone->probeToken?->equals($probeToken))->toBeTrue();

    // Verify connection mismatch is rejected immediately
    $wrongConnTx = new LockedTransactionContext('other_connection', 'tok', $currentMonotonicNs);
    expect(static fn () => $ownerAuthority->lockAndValidate($standaloneOwner, $standaloneSnap, $wrongConnTx))
        ->toThrow(LogicException::class, 'connection mismatch');

    // Prepare process-like joint claim request
    $opId = Uuid::random();
    $attemptId = Uuid::random();
    $targetId = Uuid::random();
    $routeId = Uuid::random();
    $endpointId = Uuid::random();
    $egress = EgressIdentity::explicit('nat:worker');
    $url = new SafeUrl('https://api.example.test/v1/extract');
    $timeouts = new RequestTimeoutPolicy(connectTimeoutMs: 1000, readTimeoutMs: 2000, totalTimeoutMs: 5000, cleanupMarginMs: 10_000);
    $wireRequest = new WireRequest(
        operationId: $opId,
        physicalAttemptId: $attemptId,
        targetId: $targetId,
        policyRevision: 1,
        method: 'POST',
        url: $url,
        timeouts: $timeouts,
        deadlineAt: $deadline,
        headers: new PreparedHeaders(['Content-Type' => 'application/json']),
        body: WireBody::fromString('{"prompt":"hello"}'),
        idempotency: IdempotencyClass::AmbiguousWrite,
    );
    $execContext = new ExecutionContext(
        operation: new OperationCorrelation($opId, $processOwner, $deadline, 'ext-call-1'),
        subjects: new SubjectChain(new SubjectRef($tenant, 'stage', 'extraction')),
        remainingSlice: Duration::seconds(60),
        monotonicDeadlineNs: $currentMonotonicNs + 60_000_000_000,
    );
    $routeSelection = new RouteSelection($routeId, RouteKind::Proxy, Uuid::random(), $endpointId, 1, $egress);

    // Reserve permit (idempotent on same fingerprint, conflict on tampered fingerprint)
    $permit = $admission->reserve($wireRequest, $execContext, $routeSelection);
    expect($permit)->toBeInstanceOf(ScheduledPermit::class);
    $duplicateReserve = $admission->reserve($wireRequest, $execContext, $routeSelection);
    expect($duplicateReserve)->toBe($permit);

    $tamperedWire = new WireRequest(
        operationId: $opId,
        physicalAttemptId: $attemptId,
        targetId: $targetId,
        policyRevision: 1,
        method: 'POST',
        url: $url,
        timeouts: $timeouts,
        deadlineAt: $deadline,
        headers: new PreparedHeaders(['Content-Type' => 'application/json']),
        body: WireBody::fromString('{"prompt":"tampered"}'),
        idempotency: IdempotencyClass::AmbiguousWrite,
    );
    $conflictingReserve = $admission->reserve($tamperedWire, $execContext, $routeSelection);
    expect($conflictingReserve)->toBeInstanceOf(ExecutionBlocked::class)
        ->and($conflictingReserve->reason)->toBe(BlockedReason::WireFingerprintConflict);

    $wireSnapshot = new AuthorizedWireSnapshot(
        permitId: $permit->permitId,
        routeId: $routeId,
        routeKind: RouteKind::Proxy,
        endpointId: $endpointId,
        credentialVersion: 1,
        verifiedDestination: new ResolvedUrl($url, '192.0.2.50'),
        egress: $egress,
        origin: $url->origin,
        interpretationPolicyKey: 'default',
        healthEpochs: new HealthEpochMap(['route' => 1, 'target' => 1]),
        owner: $processOwner,
        fullFingerprint: $wireRequest->fingerprint(),
        latestStartMonotonicNs: $currentMonotonicNs + 45_000_000_000,
    );

    // Negative test: rollback after issuing ClaimCandidate invalidates candidate AND rolls back financial claim
    $processSnap = $ownerAuthority->snapshot($processOwner);
    $leakedCandidate = null;
    expect(function () use ($coordinator, $ownerAuthority, $processOwner, $processSnap, $admission, $permit, $wireRequest, &$leakedCandidate, $readState, $writeState): void {
        $coordinator->run(function (LockedTransactionContext $tx) use ($ownerAuthority, $processOwner, $processSnap, $admission, $permit, $wireRequest, &$leakedCandidate, $readState, $writeState): void {
            $ownerState = $ownerAuthority->lockAndValidate($processOwner, $processSnap, $tx);
            expect($ownerState)->toBeInstanceOf(LockedOwnerState::class);

            $s = $readState();
            $s['financial_claims'][$wireRequest->physicalAttemptId->value] = 'claimed';
            $writeState($s);

            $leakedCandidate = $admission->claimSendLocked($permit, $wireRequest, $ownerState, $tx);
            expect($leakedCandidate)->toBeInstanceOf(ClaimCandidate::class);

            throw new RuntimeException('Simulated downstream failure in joint transaction');
        });
    })->toThrow(RuntimeException::class, 'Simulated downstream failure');

    expect($readState()['financial_claims'])->toBe([])
        ->and($readState()['permits'][$opId->value.':'.$attemptId->value]['state'])->toBe(PermitState::Reserved)
        ->and($leakedCandidate)->toBeInstanceOf(ClaimCandidate::class);
    expect(static fn () => $coordinator->activate($leakedCandidate, $wireRequest, $wireSnapshot))
        ->toThrow(LogicException::class);

    // Negative test: savepoint release does NOT allow activating candidate while outer transaction is still open
    $coordinator->run(function (LockedTransactionContext $outerTx) use ($coordinator, $ownerAuthority, $processOwner, $processSnap, $admission, $permit, $wireRequest, $wireSnapshot): void {
        $nestedCandidate = $coordinator->run(function (LockedTransactionContext $innerTx) use ($ownerAuthority, $processOwner, $processSnap, $admission, $permit, $wireRequest): ClaimCandidate {
            $ownerState = $ownerAuthority->lockAndValidate($processOwner, $processSnap, $innerTx);

            return $admission->claimSendLocked($permit, $wireRequest, $ownerState, $innerTx);
        });

        // Savepoint released, but outer transaction is still at level 1 -> activation must fail!
        expect(static fn () => $coordinator->activate($nestedCandidate, $wireRequest, $wireSnapshot))
            ->toThrow(LogicException::class, 'before the outermost SQL transaction has committed');
    });

    // Reset permit to Reserved to test the full successful joint claim + single-use transport + duplicate claim denial
    $state = $readState();
    $state['permits'][$opId->value.':'.$attemptId->value]['state'] = PermitState::Reserved;
    $writeState($state);

    $committedCandidate = $coordinator->run(function (LockedTransactionContext $tx) use ($ownerAuthority, $processOwner, $processSnap, $admission, $permit, $wireRequest, $readState, $writeState): ClaimCandidate {
        $ownerState = $ownerAuthority->lockAndValidate($processOwner, $processSnap, $tx);
        if ($ownerState instanceof ExecutionBlocked) {
            throw new RuntimeException('Owner blocked');
        }

        $s = $readState();
        $s['financial_claims'][$wireRequest->physicalAttemptId->value] = $wireRequest->fingerprint()->hex;
        $writeState($s);

        $claim = $admission->claimSendLocked($permit, $wireRequest, $ownerState, $tx);
        if (! $claim instanceof ClaimCandidate) {
            throw new RuntimeException('Network claim denied');
        }

        return $claim;
    });

    expect($readState()['financial_claims'][$attemptId->value])->toBe($wireRequest->fingerprint()->hex)
        ->and($readState()['permits'][$opId->value.':'.$attemptId->value]['state'])->toBe(PermitState::Authorized);

    // Activate after outermost commit without opening a second transaction
    $capability = $coordinator->activate($committedCandidate, $wireRequest, $wireSnapshot);
    expect($capability)->toBeInstanceOf(AuthorizedWireRequest::class)
        ->and($capability->isConsumed())->toBeFalse();

    // Second activation of the same candidate is rejected
    expect(static fn () => $coordinator->activate($committedCandidate, $wireRequest, $wireSnapshot))
        ->toThrow(LogicException::class);

    // Duplicate claim on already-authorized permit returns AlreadyClaimed and zero send
    $duplicateClaim = $coordinator->run(function (LockedTransactionContext $tx) use ($ownerAuthority, $processOwner, $processSnap, $admission, $permit, $wireRequest) {
        $ownerState = $ownerAuthority->lockAndValidate($processOwner, $processSnap, $tx);

        return $admission->claimSendLocked($permit, $wireRequest, $ownerState, $tx);
    });
    expect($duplicateClaim)->toBeInstanceOf(ClaimDenied::class)
        ->and($duplicateClaim->reason)->toBe(ClaimDenialReason::AlreadyClaimed)
        ->and($duplicateClaim->isDuplicateClaim())->toBeTrue();

    // PhysicalTransport consumes the single-use capability exactly once
    $transport = new class($readState, $writeState) implements PhysicalTransport
    {
        public function __construct(private readonly Closure $readState, private readonly Closure $writeState) {}

        public function sendOnce(AuthorizedWireRequest $request): AttemptResult
        {
            $request->consume();
            $s = ($this->readState)();
            $s['sent_requests']++;
            ($this->writeState)($s);

            return AttemptResult::delivered(new ResponseDescriptor(
                statusCode: 200,
                headers: ['Content-Type' => ['application/json']],
                body: new DecodedTextBody('{"ok":true}'),
                wireBytes: 11,
                decodedBytes: 11,
            ));
        }
    };

    $attemptResult = $transport->sendOnce($capability);
    expect($attemptResult->wasDelivered())->toBeTrue()
        ->and($capability->isConsumed())->toBeTrue()
        ->and($readState()['sent_requests'])->toBe(1);

    // Second sendOnce on consumed capability is rejected with zero additional sends
    expect(static fn () => $transport->sendOnce($capability))->toThrow(LogicException::class, 'already consumed');
    expect($readState()['sent_requests'])->toBe(1);

    // Expired monotonic latestStart on activation is rejected
    $expiredAttemptId = Uuid::random();
    $expiredWire = new WireRequest($opId, $expiredAttemptId, $targetId, 1, 'GET', $url, $timeouts, $deadline);
    $expiredPermit = $admission->reserve($expiredWire, $execContext, $routeSelection);
    $expiredCandidate = $coordinator->run(function (LockedTransactionContext $tx) use ($ownerAuthority, $processOwner, $processSnap, $admission, $expiredPermit, $expiredWire): ClaimCandidate {
        $ownerState = $ownerAuthority->lockAndValidate($processOwner, $processSnap, $tx);

        return $admission->claimSendLocked($expiredPermit, $expiredWire, $ownerState, $tx);
    });
    $expiredSnapshot = new AuthorizedWireSnapshot(
        permitId: $expiredPermit->permitId,
        routeId: $routeId,
        routeKind: RouteKind::Proxy,
        endpointId: $endpointId,
        credentialVersion: 1,
        verifiedDestination: new ResolvedUrl($url, '192.0.2.50'),
        egress: $egress,
        origin: $url->origin,
        interpretationPolicyKey: 'default',
        healthEpochs: new HealthEpochMap(['route' => 1, 'target' => 1]),
        owner: $processOwner,
        fullFingerprint: $expiredWire->fingerprint(),
        latestStartMonotonicNs: $currentMonotonicNs + 45_000_000_000,
    );

    // Advance monotonic clock past conservative latestStart (60s DB window - 15s total+cleanup = 45s)
    $currentMonotonicNs += 46_000_000_000;
    expect(static fn () => $coordinator->activate($expiredCandidate, $expiredWire, $expiredSnapshot))
        ->toThrow(LogicException::class, 'expired');
});
