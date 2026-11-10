<?php

declare(strict_types=1);

use Proxyma\Application\Admission\ClaimSend;
use Proxyma\Application\Admission\QuotaService;
use Proxyma\Application\Execution\DestinationPolicy;
use Proxyma\Application\Execution\OutboundExecutor;
use Proxyma\Application\Execution\OutcomePolicyRegistry;
use Proxyma\Application\Execution\ProxyResolver;
use Proxyma\Application\Policy\PolicyResolver;
use Proxyma\Application\Recovery\CompletionCoordinator;
use Proxyma\Application\Routing\RouteSelector;
use Proxyma\Contracts\CatalogQueries;
use Proxyma\Contracts\OutcomePolicy;
use Proxyma\Contracts\PhysicalTransport;
use Proxyma\Contracts\SecretResolver;
use Proxyma\Contracts\SharedRouteCommands;
use Proxyma\Contracts\TlsTrustPolicy;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\CredentialRef;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Catalog\ShareRouteCommand;
use Proxyma\Domain\Catalog\UpsertRouteCommand;
use Proxyma\Domain\Catalog\UpsertRouteProfileCommand;
use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\AuthorizedWireRequest;
use Proxyma\Domain\Execution\BlockedReason;
use Proxyma\Domain\Execution\DeferralReason;
use Proxyma\Domain\Execution\Duration;
use Proxyma\Domain\Execution\ExecutionBlocked;
use Proxyma\Domain\Execution\ExecutionContext;
use Proxyma\Domain\Execution\ExecutionDeferred;
use Proxyma\Domain\Execution\ExecutionSucceeded;
use Proxyma\Domain\Execution\ExecutionUnknownAfterAuthorization;
use Proxyma\Domain\Execution\HttpResponseHead;
use Proxyma\Domain\Execution\IdempotencyClass;
use Proxyma\Domain\Execution\OperationCorrelation;
use Proxyma\Domain\Execution\OutboundRequest;
use Proxyma\Domain\Execution\OutcomeClassification;
use Proxyma\Domain\Execution\OutcomeDecision;
use Proxyma\Domain\Execution\OwnerRef;
use Proxyma\Domain\Execution\PreparedHeaders;
use Proxyma\Domain\Execution\RawResponseBody;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\ResolvedSecret;
use Proxyma\Domain\Execution\ResponseDescriptor;
use Proxyma\Domain\Execution\RetryAt;
use Proxyma\Domain\Execution\RouteSelection;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Execution\TlsTrustConfiguration;
use Proxyma\Domain\Execution\TransportFailure;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireBody;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Health\HealthAttribution;
use Proxyma\Domain\Outcomes\AttemptFacts;
use Proxyma\Domain\Outcomes\CallerOutcomeAction;
use Proxyma\Domain\Outcomes\DefaultOutcomePolicy;
use Proxyma\Domain\Outcomes\OutcomePolicyTier;
use Proxyma\Domain\Outcomes\RetryAfterParser;
use Proxyma\Domain\Outcomes\TechnicalOutcomeClassifier;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Http\DnsResolver;
use Proxyma\Tests\Support\CatalogTestCase;
use Proxyma\Tests\Support\ClaimFixtures;
use Proxyma\Tests\Support\DemandFixtures;
use Proxyma\Tests\Support\QuotaFixtures;
use Proxyma\Tests\Support\ReservationFixtures;

foreach (['CatalogTestCase', 'SelectionFixtures', 'DemandFixtures', 'ReservationFixtures', 'ClaimFixtures', 'QuotaFixtures'] as $file) {
    require_once __DIR__.'/../Support/'.$file.'.php';
}

uses(CatalogTestCase::class, DemandFixtures::class, ReservationFixtures::class, ClaimFixtures::class, QuotaFixtures::class);

beforeEach(function (): void {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    config(['proxyma.policy.project_defaults' => [
        'route.allow_direct' => true,
        'retry.max_attempts' => 10,
        'http.max_redirects' => 10,
    ]]);
    $this->initializeClaimFixture();
    $this->table('budgets')->update(['burst' => 100, 'max_inflight' => 100, 'emission_interval_us' => 1]);
    $this->sleptUs = [];
    $this->dispatched = [];
});

/**
 * @param  list<AttemptResult|(Closure(AuthorizedWireRequest, int): AttemptResult)>  $script
 * @param  array<string, array<string, list<string>>>  $privateTargets
 * @param  array<string, list<string>>  $dnsRecords
 * @param  array<string, ?ResolvedSecret>  $secrets
 */
function makeExecutorForTest(
    object $test,
    array $script,
    ?OutcomePolicyRegistry $outcomes = null,
    array $privateTargets = [],
    array $dnsRecords = ['example.test' => ['93.184.216.34'], 'proxy.test' => ['198.51.100.10']],
    array $secrets = [],
): OutboundExecutor {
    return (function () use ($script, $outcomes, $privateTargets, $dnsRecords, $secrets): OutboundExecutor {
        if ($outcomes !== null) {
            $this->app->instance(OutcomePolicyRegistry::class, $outcomes);
            $this->app->forgetInstance(ClaimSend::class);
            $this->claims = $this->app->make(ClaimSend::class);
        }
        $scope = $this->transactions->scope;
        $dns = new DnsResolver($scope, static fn (string $host): array => $dnsRecords[$host] ?? ['93.184.216.34']);
        $destinations = new DestinationPolicy($dns, $privateTargets, [80, 443, 8080, 8443]);
        $proxies = new ProxyResolver($dns);

        $secretResolver = new class($secrets) implements SecretResolver
        {
            /** @param array<string, ?ResolvedSecret> $secrets */
            public function __construct(private readonly array $secrets) {}

            public function resolve(CredentialRef $credential, TenantKey $tenant): ?ResolvedSecret
            {
                $key = $credential->reference.'@'.$credential->version;
                if (array_key_exists($key, $this->secrets)) {
                    return $this->secrets[$key];
                }

                return new ResolvedSecret($credential, 'proxy-user', 'proxy-pass');
            }
        };

        $tlsTrust = new class implements TlsTrustPolicy
        {
            public function tlsConfigurationFor(CanonicalOrigin $origin, Uuid $targetId): TlsTrustConfiguration
            {
                return new TlsTrustConfiguration;
            }

            public function proxyConfigurationFor(string $originalHost): TlsTrustConfiguration
            {
                return new TlsTrustConfiguration;
            }
        };

        $recordDispatch = function (AuthorizedWireRequest $request): void {
            $this->dispatched[] = $request;
        };

        $transport = new class($script, $recordDispatch) implements PhysicalTransport
        {
            private int $index = 0;

            /**
             * @param  list<AttemptResult|(Closure(AuthorizedWireRequest, int): AttemptResult)>  $script
             * @param  Closure(AuthorizedWireRequest): void  $recordDispatch
             */
            public function __construct(
                private array $script,
                private Closure $recordDispatch,
            ) {}

            public function sendOnce(AuthorizedWireRequest $request): AttemptResult
            {
                $request->consume();
                $callIndex = $this->index++;
                ($this->recordDispatch)($request);
                $entry = $this->script[$callIndex] ?? $this->script[count($this->script) - 1] ?? null;
                if ($entry instanceof Closure) {
                    return $entry($request, $callIndex);
                }
                if ($entry instanceof AttemptResult) {
                    return $entry;
                }

                return AttemptResult::delivered(new ResponseDescriptor(200, ['content-type' => ['text/plain']], new RawResponseBody('ok'), 2, 2));
            }
        };

        $registry = $outcomes ?? new OutcomePolicyRegistry;
        if (! $registry->isFrozen()) {
            $registry->freeze();
        }

        return new OutboundExecutor(
            scope: $scope,
            catalog: $this->app->make(CatalogQueries::class),
            policies: $this->app->make(PolicyResolver::class),
            destinations: $destinations,
            selector: $this->app->make(RouteSelector::class),
            proxies: $proxies,
            secrets: $secretResolver,
            tlsTrust: $tlsTrust,
            sealer: $this->sealer,
            operations: $this->operations,
            reservations: $this->reservations,
            claims: $this->claims,
            transport: $transport,
            outcomes: $registry,
            completion: $this->app->make(CompletionCoordinator::class),
            sleepUs: function (int $us): void {
                $this->sleptUs[] = $us;
            },
        );
    })->call($test);
}

/**
 * @param  array<string, list<string>>  $headers
 */
function deliveredHttp(int $status, string $body = 'response-body', array $headers = ['content-type' => ['text/plain']]): AttemptResult
{
    $len = strlen($body);

    return AttemptResult::delivered(new ResponseDescriptor($status, $headers, new RawResponseBody($body), $len, $len));
}

function makeExecutionPair(
    object $test,
    string $method = 'GET',
    string $url = 'https://example.test/resource',
    ?RequestTimeoutPolicy $timeouts = null,
    IdempotencyClass $idempotency = IdempotencyClass::ReadOnly,
    ?PreparedHeaders $headers = null,
    ?WireBody $body = null,
    ?int $sendLimit = null,
    int $remainingSliceMs = 60_000,
    int $monotonicBudgetMs = 60_000,
    string $deadlineOffset = '+2 minutes',
    ?string $outcomePolicyKey = null,
): array {
    return (function () use (
        $method,
        $url,
        $timeouts,
        $idempotency,
        $headers,
        $body,
        $sendLimit,
        $remainingSliceMs,
        $monotonicBudgetMs,
        $deadlineOffset,
        $outcomePolicyKey,
    ): array {
        $operationId = Uuid::random();
        $owner = new OwnerRef($this->tenant, 'standalone', $operationId->value, 'fixture-token');
        $operation = new OperationCorrelation(
            operationId: $operationId,
            owner: $owner,
            deadlineAt: new DateTimeImmutable($deadlineOffset),
            externalOperationKey: 'exec:'.$operationId->value,
        );
        $subjects = new SubjectChain(new SubjectRef($this->tenant, 'document', 'fixture'));
        $context = new ExecutionContext(
            operation: $operation,
            subjects: $subjects,
            remainingSlice: new Duration($remainingSliceMs),
            monotonicDeadlineNs: hrtime(true) + ($monotonicBudgetMs * 1_000_000),
        );
        $request = new OutboundRequest(
            operationId: $operationId,
            targetId: $this->catalog['target'],
            method: $method,
            url: new SafeUrl($url),
            timeouts: $timeouts ?? new RequestTimeoutPolicy(500, 1000, 2000, 1000, maxAttempts: 3, maxDistinctRoutes: 3, maxRedirects: 0),
            idempotency: $idempotency,
            headers: $headers ?? new PreparedHeaders,
            body: $body,
            trafficKey: 'default',
            sendLimit: $sendLimit,
            outcomePolicyKey: $outcomePolicyKey,
        );

        return [$request, $context];
    })->call($test);
}

it('ограничивает повторы по maxAttempts, maxDistinctRoutes и sendLimit с новым permit на каждую попытку', function (): void {
    $extraRoutes = selectionAddRoutes($this->db, $this->tenant, $this->catalog['profile'], $this->catalog['target'], 2);
    foreach ([$this->catalog['route'], ...$extraRoutes] as $routeId) {
        $scopeKey = CanonicalOrigin::of('https', 'example.test')->routeScopeKey($routeId);
        if (! $this->table('budgets')->where('scope_key', $scopeKey)->exists()) {
            $this->budget($scopeKey);
        }
    }
    $egressShared = CanonicalOrigin::of('https', 'example.test')->egressScopeKey('pool:shared');
    if (! $this->table('budgets')->where('scope_key', $egressShared)->exists()) {
        $this->budget($egressShared);
    }
    $this->table('budgets')->update(['burst' => 100, 'max_inflight' => 100, 'emission_interval_us' => 1]);

    // 1) maxAttempts = 3 при постоянном 503 останавливается ровно после 3 физических попыток.
    $executor = makeExecutorForTest($this, [
        deliveredHttp(503, 'overload-1'),
        deliveredHttp(503, 'overload-2'),
        deliveredHttp(503, 'overload-3'),
        deliveredHttp(200, 'should-not-run'),
    ]);
    [$request, $context] = makeExecutionPair(
        $this,
        timeouts: new RequestTimeoutPolicy(500, 1000, 2000, 1000, maxAttempts: 3, maxDistinctRoutes: 3, retryDelaysMs: [10, 20]),
    );
    $outcome = $executor->execute($request, $context);

    expect($outcome)->toBeInstanceOf(ExecutionDeferred::class)
        ->and($outcome->reason)->toBe(DeferralReason::AttemptsExhausted)
        ->and($outcome->attemptIds)->toHaveCount(3)
        ->and($this->dispatched)->toHaveCount(3)
        ->and($this->sleptUs)->toBe([10_000, 20_000])
        ->and($this->table('permits')->where('operation_id', $request->operationId->value)->count())->toBe(3)
        ->and($this->table('attempts')->where('operation_id', $request->operationId->value)->where('status', 'completed')->count())->toBe(3);

    // 2) maxDistinctRoutes = 2 при 3 доступных маршрутах и ошибках соединения пробует ровно 2 маршрута.
    $this->dispatched = [];
    $executorDistinct = makeExecutorForTest($this, [
        AttemptResult::failed(TransportFailure::Connect),
        AttemptResult::failed(TransportFailure::Connect),
        AttemptResult::failed(TransportFailure::Connect),
    ]);
    [$request2, $context2] = makeExecutionPair(
        $this,
        timeouts: new RequestTimeoutPolicy(500, 1000, 2000, 1000, maxAttempts: 3, maxDistinctRoutes: 2),
    );
    $outcome2 = $executorDistinct->execute($request2, $context2);
    $usedRouteIds = array_values(array_unique(array_map(
        static fn (AuthorizedWireRequest $req): string => $req->snapshot->routeId->value,
        $this->dispatched,
    )));

    expect($outcome2)->toBeInstanceOf(ExecutionDeferred::class)
        ->and($outcome2->reason)->toBe(DeferralReason::AttemptsExhausted)
        ->and($outcome2->attemptIds)->toHaveCount(2)
        ->and($usedRouteIds)->toHaveCount(2);

    // 3) sendLimit = 2 ограничивает число отправок даже при maxAttempts = 4.
    $this->dispatched = [];
    $executorSendLimit = makeExecutorForTest($this, [
        deliveredHttp(503),
        deliveredHttp(503),
        deliveredHttp(200),
    ]);
    [$request3, $context3] = makeExecutionPair(
        $this,
        timeouts: new RequestTimeoutPolicy(500, 1000, 2000, 1000, maxAttempts: 4, maxDistinctRoutes: 3),
        sendLimit: 2,
    );
    $outcome3 = $executorSendLimit->execute($request3, $context3);

    expect($outcome3)->toBeInstanceOf(ExecutionDeferred::class)
        ->and($outcome3->reason)->toBe(DeferralReason::AttemptsExhausted)
        ->and($outcome3->attemptIds)->toHaveCount(2)
        ->and($this->dispatched)->toHaveCount(2);
});

it('проверяет remainingSlice и deadline до DNS и admission без лишнего permit или сетевого вызова', function (): void {
    $dnsCalled = false;
    $scope = $this->transactions->scope;
    $dns = new DnsResolver($scope, static function () use (&$dnsCalled): array {
        $dnsCalled = true;

        return ['93.184.216.34'];
    });
    $executor = new OutboundExecutor(
        scope: $scope,
        catalog: $this->app->make(CatalogQueries::class),
        policies: $this->app->make(PolicyResolver::class),
        destinations: new DestinationPolicy($dns),
        selector: $this->app->make(RouteSelector::class),
        proxies: new ProxyResolver($dns),
        secrets: $this->app->make(SecretResolver::class),
        tlsTrust: $this->app->make(TlsTrustPolicy::class),
        sealer: $this->sealer,
        operations: $this->operations,
        reservations: $this->reservations,
        claims: $this->claims,
        transport: $this->app->make(PhysicalTransport::class),
        outcomes: new OutcomePolicyRegistry,
        completion: $this->app->make(CompletionCoordinator::class),
    );

    // Срез 1500ms меньше, чем totalTimeoutMs (2000ms) + cleanupMarginMs (1000ms) = 3000ms.
    [$request, $context] = makeExecutionPair(
        $this,
        timeouts: new RequestTimeoutPolicy(500, 1000, 2000, 1000, maxAttempts: 2, maxDistinctRoutes: 1),
        remainingSliceMs: 1500,
    );
    $outcome = $executor->execute($request, $context);

    expect($outcome)->toBeInstanceOf(ExecutionDeferred::class)
        ->and($outcome->reason)->toBe(DeferralReason::SliceExhausted)
        ->and($outcome->attemptIds)->toBe([])
        ->and($dnsCalled)->toBeFalse()
        ->and($this->table('permits')->where('operation_id', $request->operationId->value)->count())->toBe(0);

    // Если первая попытка проходит, но retryDelay + вторая попытка не помещаются в remainingSlice — останавливается без сна.
    $this->sleptUs = [];
    $executorAfterFirst = makeExecutorForTest($this, [
        deliveredHttp(503, 'retry-later'),
        deliveredHttp(200, 'unreachable'),
    ]);
    [$request2, $context2] = makeExecutionPair(
        $this,
        timeouts: new RequestTimeoutPolicy(500, 1000, 2000, 1000, maxAttempts: 2, maxDistinctRoutes: 1, retryDelaysMs: [2000]),
        remainingSliceMs: 3500,
    );
    $outcome2 = $executorAfterFirst->execute($request2, $context2);

    expect($outcome2)->toBeInstanceOf(ExecutionDeferred::class)
        ->and($outcome2->reason)->toBe(DeferralReason::SliceExhausted)
        ->and($outcome2->attemptIds)->toHaveCount(1)
        ->and($this->sleptUs)->toBe([]);
});

it('классифицирует 404 и 401 как доставленные ответы без штрафа здоровью маршрута и без повторов', function (int $status, string $meaning): void {
    $executor = makeExecutorForTest($this, [
        deliveredHttp($status, 'domain-payload-'.$status),
        deliveredHttp(200, 'should-not-retry'),
    ]);
    [$request, $context] = makeExecutionPair(
        $this,
        timeouts: new RequestTimeoutPolicy(500, 1000, 2000, 1000, maxAttempts: 3, maxDistinctRoutes: 1),
    );

    $outcome = $executor->execute($request, $context);

    expect($outcome)->toBeInstanceOf(ExecutionSucceeded::class)
        ->and($outcome->response->statusCode)->toBe($status)
        ->and($outcome->attemptIds)->toHaveCount(1)
        ->and($outcome->outcome?->decision)->toBe(OutcomeDecision::Accept)
        ->and($outcome->outcome?->businessMeaning)->toBe($meaning)
        ->and($outcome->outcome?->healthAttribution)->toBe(HealthAttribution::RouteSuccess);

    $routeHealth = $this->table('route_health')->where('route_id', $this->catalog['route']->value)->first();
    expect($routeHealth->state)->toBe('closed')
        ->and((int) $routeHealth->consecutive_failures)->toBe(0);
})->with([
    '404 Not Found' => [404, 'origin_not_found'],
    '401 Unauthorized' => [401, 'origin_unauthorized'],
    '403 without WAF interpretation' => [403, 'origin_forbidden'],
]);

it('configuration hold сохраняет scalar details без отправки или списания', function (): void {
    $this->table('target_health')->delete();
    [$request, $context] = makeExecutionPair($this);
    $result = makeExecutorForTest($this, [deliveredHttp(200)])->execute($request, $context);
    expect($result)->toBeInstanceOf(ExecutionBlocked::class)
        ->and($result->reason)->toBe(BlockedReason::ConfigurationHold)
        ->and($result->safeDetails['holds'])->toBe('target_health_unavailable')
        ->and($this->dispatched)->toBe([])
        ->and($this->table('attempts')->count())->toBe(0)
        ->and($this->table('permits')->count())->toBe(0);
});

it('при 407 ставит credential_hold на прокси-маршрут, переключается на другой прокси и никогда не падает в direct', function (): void {
    config(['proxyma.policy.project_defaults' => array_replace(config('proxyma.policy.project_defaults'), ['route.allow_direct' => false])]);
    $endpoint1 = Uuid::random();
    $endpoint2 = Uuid::random();
    $cred1 = new CredentialRef('proxy:cred1', 1);
    $cred2 = new CredentialRef('proxy:cred2', 1);

    foreach ([[$endpoint1, 8081, $cred1], [$endpoint2, 8082, $cred2]] as [$epId, $port, $ref]) {
        $this->table('endpoints')->insert([
            'id' => $epId->value,
            'tenant_key' => $this->tenant->value,
            'protocol' => ProxyProtocol::Http->value,
            'host' => 'proxy.test',
            'port' => $port,
            'credential_ref' => $ref->reference,
            'credential_version' => $ref->version,
            'revision' => 1,
        ]);
    }

    $proxyRoute1 = Uuid::random();
    $proxyRoute2 = Uuid::random();
    $commands = $this->commands();
    $commands->upsertRoute(new UpsertRouteCommand(
        $proxyRoute1,
        $this->tenant,
        'proxy-one',
        RouteKind::Proxy,
        $endpoint1,
        true,
        EgressIdentity::explicit('egress:proxy-1'),
        null,
        false,
        $this->actor(),
    ));
    $commands->upsertRoute(new UpsertRouteCommand(
        $proxyRoute2,
        $this->tenant,
        'proxy-two',
        RouteKind::Proxy,
        $endpoint2,
        true,
        EgressIdentity::explicit('egress:proxy-2'),
        null,
        false,
        $this->actor(),
    ));
    // В профиле также есть direct-маршрут $this->catalog['route'], но policy по умолчанию НЕ разрешает direct fallback.
    $commands->upsertProfile(new UpsertRouteProfileCommand(
        $this->catalog['profile'],
        $this->tenant,
        'default',
        [$proxyRoute1, $proxyRoute2, $this->catalog['route']],
        'uniform_random',
        $this->actor(revision: 1),
    ));

    selectionHealth($this->db, $this->tenant);
    $origin = CanonicalOrigin::of('https', 'example.test');
    foreach ([[$proxyRoute1, 'egress:proxy-1'], [$proxyRoute2, 'egress:proxy-2']] as [$rId, $egKey]) {
        $this->budget($origin->routeScopeKey($rId));
        $this->budget($origin->egressScopeKey($egKey));
    }
    $this->table('budgets')->update(['burst' => 100, 'max_inflight' => 100, 'emission_interval_us' => 1]);

    // Сценарий A: первый прокси возвращает 407 ProxyAuth -> ставится credential_hold, вторая попытка идёт через второй прокси -> 200 OK.
    $executor = makeExecutorForTest($this, [
        AttemptResult::failed(TransportFailure::ProxyAuth, partialHead: new HttpResponseHead(407, [])),
        deliveredHttp(200, 'via-second-proxy'),
    ]);
    [$request, $context] = makeExecutionPair(
        $this,
        timeouts: new RequestTimeoutPolicy(500, 1000, 2000, 1000, maxAttempts: 3, maxDistinctRoutes: 3),
    );
    $outcome = $executor->execute($request, $context);

    expect($outcome)->toBeInstanceOf(ExecutionSucceeded::class)
        ->and($outcome->attemptIds)->toHaveCount(2)
        ->and($this->dispatched)->toHaveCount(2);

    $firstRouteId = $this->dispatched[0]->snapshot->routeId;
    $secondRouteId = $this->dispatched[1]->snapshot->routeId;
    expect($firstRouteId->equals($secondRouteId))->toBeFalse()
        ->and($secondRouteId->equals($this->catalog['route']))->toBeFalse();

    $firstRouteHealth = $this->table('route_health')->where('route_id', $firstRouteId->value)->first();
    expect($firstRouteHealth->state)->toBe('open')
        ->and($firstRouteHealth->reason_code)->toBe('credential_hold:1');

    // Сценарий B: когда второй прокси тоже возвращает 407, система блокирует выполнение (CredentialHold) и НЕ использует direct-маршрут.
    $this->dispatched = [];
    $executorHoldAll = makeExecutorForTest($this, [
        AttemptResult::failed(TransportFailure::ProxyAuth),
        deliveredHttp(200, 'direct-must-not-run'),
    ]);
    [$request2, $context2] = makeExecutionPair(
        $this,
        timeouts: new RequestTimeoutPolicy(500, 1000, 2000, 1000, maxAttempts: 3, maxDistinctRoutes: 3),
    );
    $outcome2 = $executorHoldAll->execute($request2, $context2);

    expect($outcome2)->toBeInstanceOf(ExecutionBlocked::class)
        ->and($outcome2->reason)->toBe(BlockedReason::CredentialHold)
        ->and($this->dispatched)->toHaveCount(1)
        ->and($this->dispatched[0]->snapshot->routeId->equals($this->catalog['route']))->toBeFalse();
});

it('при 429 парсит Retry-After, продлевает origin cooldown и не переключает маршруты в том же всплеске', function (): void {
    $extraRoutes = selectionAddRoutes($this->db, $this->tenant, $this->catalog['profile'], $this->catalog['target'], 1);
    $origin = CanonicalOrigin::of('https', 'example.test');
    $this->budget($origin->routeScopeKey($extraRoutes[0]));
    $this->budget($origin->egressScopeKey('pool:shared'));
    $this->table('budgets')->update(['burst' => 100, 'max_inflight' => 100, 'emission_interval_us' => 1]);

    $executor = makeExecutorForTest($this, [
        deliveredHttp(429, 'rate-limited', ['retry-after' => ['120']]),
        deliveredHttp(200, 'must-not-rotate-on-429'),
    ]);
    [$request, $context] = makeExecutionPair(
        $this,
        timeouts: new RequestTimeoutPolicy(500, 1000, 2000, 1000, maxAttempts: 3, maxDistinctRoutes: 2),
    );

    $before = new DateTimeImmutable('-1 second');
    $outcome = $executor->execute($request, $context);

    expect($outcome)->toBeInstanceOf(ExecutionDeferred::class)
        ->and($outcome->reason)->toBe(DeferralReason::RateLimit)
        ->and($outcome->attemptIds)->toHaveCount(1)
        ->and($this->dispatched)->toHaveCount(1)
        ->and($outcome->retryAt->isAfter($before->modify('+110 seconds')))->toBeTrue();

    $targetHealth = $this->table('target_health')->where('target_id', $this->catalog['target']->value)->first();
    expect($targetHealth->state)->toBe('closed')
        ->and($targetHealth->reason_code)->toBeNull()
        ->and($targetHealth->open_until)->toBeNull()
        ->and(new DateTimeImmutable($this->table('budgets')->where('scope_key', $this->scopeKey)->value('cooldown_until')))
        ->toBeGreaterThan($before->modify('+110 seconds'));

    // Проверим также парсер Retry-After на HTTP-date, сверхбольших значениях и мусоре.
    $parser = new RetryAfterParser(maxCeilingSeconds: 86_400, fallbackSeconds: 60);
    $now = new DateTimeImmutable('2026-11-10T12:00:00Z');

    $dateAdvice = $parser->parse('Tue, 10 Nov 2026 12:05:00 GMT', $now);
    expect($dateAdvice->delaySeconds)->toBe(300)
        ->and($dateAdvice->fromHeader)->toBeTrue()
        ->and($dateAdvice->isExcessive)->toBeFalse();

    $cappedAdvice = $parser->parse('999999999', $now);
    expect($cappedAdvice->delaySeconds)->toBe(86_400)
        ->and($cappedAdvice->isExcessive)->toBeTrue();

    $invalidAdvice = $parser->parse('not-a-valid-retry-after', $now);
    expect($invalidAdvice->delaySeconds)->toBe(60)
        ->and($invalidAdvice->isInvalid)->toBeTrue()
        ->and($invalidAdvice->fromHeader)->toBeTrue();
});

it('повторяет 502/503/504 для идемпотентного запроса с отдельными permit и attemptIds', function (int $status): void {
    $executor = makeExecutorForTest($this, [
        deliveredHttp($status, 'temporary-server-error'),
        deliveredHttp(200, 'recovered-body'),
    ]);
    [$request, $context] = makeExecutionPair(
        $this,
        method: 'GET',
        timeouts: new RequestTimeoutPolicy(500, 1000, 2000, 1000, maxAttempts: 3, maxDistinctRoutes: 1),
    );

    $outcome = $executor->execute($request, $context);

    expect($outcome)->toBeInstanceOf(ExecutionSucceeded::class)
        ->and($outcome->response->statusCode)->toBe(200)
        ->and($outcome->response->body->bytes())->toBe('recovered-body')
        ->and($outcome->attemptIds)->toHaveCount(2)
        ->and($outcome->attemptIds[0]->equals($outcome->attemptIds[1]))->toBeFalse()
        ->and($this->dispatched[0]->snapshot->permitId->equals($this->dispatched[1]->snapshot->permitId))->toBeFalse();
})->with([502, 503, 504]);

it('не повторяет неоднозначный POST после авторизации при обрыве или таймауте и переводит попытку в unknown_after_authorization', function (TransportFailure $failure): void {
    $executor = makeExecutorForTest($this, [
        AttemptResult::failed($failure, wireBytes: 32),
        deliveredHttp(200, 'must-never-be-sent'),
    ]);
    [$request, $context] = makeExecutionPair(
        $this,
        method: 'POST',
        timeouts: new RequestTimeoutPolicy(500, 1000, 2000, 1000, maxAttempts: 3, maxDistinctRoutes: 2),
        idempotency: IdempotencyClass::AmbiguousWrite,
        body: WireBody::fromString('{"amount":100}'),
    );

    $outcome = $executor->execute($request, $context);

    expect($outcome)->toBeInstanceOf(ExecutionUnknownAfterAuthorization::class)
        ->and($outcome->operationId->equals($request->operationId))->toBeTrue()
        ->and($outcome->failure)->toBe($failure)
        ->and($this->dispatched)->toHaveCount(1);

    $permitRow = $this->table('permits')->where('id', $outcome->permitId->value)->first();
    $attemptRow = $this->table('attempts')->where('physical_attempt_id', $outcome->attemptId->value)->first();
    expect($permitRow->state)->toBe('unknown_after_authorization')
        ->and($attemptRow->status)->toBe('unknown_after_authorization');
})->with([
    'timeout after send' => [TransportFailure::Timeout],
    'truncated response' => [TransportFailure::Truncated],
    'connection drop' => [TransportFailure::Connect],
]);

it('соблюдает приоритет уровней OutcomePolicyRegistry и не позволяет переопределить инварианты безопасности', function (): void {
    $registry = new OutcomePolicyRegistry(
        new TechnicalOutcomeClassifier,
        new RetryAfterParser,
    );

    // Регистрация политик на разных уровнях (Default < Panel < Project < SecurityOwnership).
    $panelPolicy = new class implements OutcomePolicy
    {
        public function key(): string
        {
            return 'tiered';
        }

        public function classify(WireRequest $request, AttemptResult $attempt): OutcomeClassification
        {
            return new OutcomeClassification(OutcomeDecision::RetrySameRoute, 'panel.custom_retry', HealthAttribution::RouteSuccess);
        }
    };
    $projectPolicy = new class implements OutcomePolicy
    {
        public function key(): string
        {
            return 'tiered';
        }

        public function classify(WireRequest $request, AttemptResult $attempt): OutcomeClassification
        {
            return new OutcomeClassification(
                OutcomeDecision::Defer,
                'project.custom_defer',
                HealthAttribution::RouteSuccess,
                new RetryAt(new DateTimeImmutable('+30 seconds')),
            );
        }
    };
    $securityPolicy = new class implements OutcomePolicy
    {
        public function key(): string
        {
            return 'tiered';
        }

        public function classify(WireRequest $request, AttemptResult $attempt): OutcomeClassification
        {
            return new OutcomeClassification(OutcomeDecision::Block, 'security.custom_block', HealthAttribution::RouteSuccess);
        }
    };

    $registry->register('tiered', $panelPolicy, OutcomePolicyTier::Panel);
    $registry->register('tiered', $projectPolicy, OutcomePolicyTier::Project);
    $registry->register('tiered', $securityPolicy, OutcomePolicyTier::SecurityOwnership);

    // Конфликт одного уровня отклоняется при freeze().
    $conflictRegistry = new OutcomePolicyRegistry;
    $conflictRegistry->register('dup', $panelPolicy, OutcomePolicyTier::Project);
    $conflictRegistry->register('dup', $projectPolicy, OutcomePolicyTier::Project);
    expect(fn () => $conflictRegistry->freeze())->toThrow(LogicException::class, 'Equal-priority outcome policy conflict');
    $globalConflict = new OutcomePolicyRegistry;
    $globalConflict->register('default', $securityPolicy, OutcomePolicyTier::SecurityOwnership);
    $globalConflict->register('tiered', $securityPolicy, OutcomePolicyTier::SecurityOwnership);
    expect(fn () => $globalConflict->freeze())->toThrow(LogicException::class, 'Equal-priority outcome policy conflict');

    // Попытка нарушить безопасность (разрешить retry на AmbiguousWrite timeout или игнорировать 429) принудительно блокируется.
    $unsafePolicy = new class implements OutcomePolicy
    {
        public function key(): string
        {
            return 'unsafe-override';
        }

        public function classify(WireRequest $request, AttemptResult $attempt): OutcomeClassification
        {
            return new OutcomeClassification(OutcomeDecision::RetryDifferentRoute, 'unsafe.override', HealthAttribution::RouteSuccess);
        }
    };
    $registry->register('unsafe-override', $unsafePolicy, OutcomePolicyTier::Project);
    $registry->register('waf-opt-in', new DefaultOutcomePolicy(policyKey: 'waf-opt-in'), OutcomePolicyTier::Project, wafRateSignal: true);
    $registry->freeze();

    // 1) Приоритет SecurityOwnership побеждает Project и Panel.
    $executorTiered = makeExecutorForTest($this, [deliveredHttp(200, 'ok')], outcomes: $registry);
    [$reqTier, $ctxTier] = makeExecutionPair($this, outcomePolicyKey: 'tiered');
    $resTier = $executorTiered->execute($reqTier, $ctxTier);
    expect($resTier)->toBeInstanceOf(ExecutionBlocked::class)
        ->and($resTier->reason)->toBe(BlockedReason::PolicyReview)
        ->and($resTier->safeDetails['business_meaning'])->toBe('security.custom_block');

    // 2) Небезопасная политика не может форсировать ротацию на 429.
    $this->dispatched = [];
    $executorUnsafe429 = makeExecutorForTest($this, [
        deliveredHttp(429, 'rate-limit', ['retry-after' => ['45']]),
        deliveredHttp(200, 'must-not-run'),
    ], outcomes: $registry);
    [$req429, $ctx429] = makeExecutionPair($this, outcomePolicyKey: 'unsafe-override');
    $res429 = $executorUnsafe429->execute($req429, $ctx429);
    expect($res429)->toBeInstanceOf(ExecutionDeferred::class)
        ->and($res429->reason)->toBe(DeferralReason::RateLimit)
        ->and($this->dispatched)->toHaveCount(1);

});

it('применяет opt-in WAF 403 только к паре маршрута и цели', function (): void {
    $registry = new OutcomePolicyRegistry;
    $registry->register('waf-opt-in', new DefaultOutcomePolicy(policyKey: 'waf-opt-in'), wafRateSignal: true);
    $registry->freeze();
    $executorWaf = makeExecutorForTest($this, [deliveredHttp(403, 'waf-challenge')], outcomes: $registry);
    [$reqWaf, $ctxWaf] = makeExecutionPair($this, outcomePolicyKey: 'waf-opt-in');
    $resWaf = $executorWaf->execute($reqWaf, $ctxWaf);
    expect($resWaf)->toBeInstanceOf(ExecutionDeferred::class)
        ->and($resWaf->reason)->toBe(DeferralReason::TargetCooldown);

    $pairHealth = $this->table('route_target_health')
        ->where('route_id', $this->catalog['route']->value)
        ->where('target_id', $this->catalog['target']->value)
        ->first();
    $globalRouteHealth = $this->table('route_health')->where('route_id', $this->catalog['route']->value)->first();
    expect($pairHealth->reason_code)->toBe('waf_rate_signal')
        ->and($globalRouteHealth->state)->toBe('closed');
});

it('передаёт callback факты реального маршрута и не позволяет принять TLS failure', function (): void {
    $seen = null;
    $registry = new OutcomePolicyRegistry;
    $registry->register('callback', function (AttemptFacts $facts) use (&$seen): CallerOutcomeAction {
        $seen = $facts;

        return CallerOutcomeAction::ReturnResponse;
    });
    $executor = makeExecutorForTest($this, [AttemptResult::failed(TransportFailure::Tls), deliveredHttp(200)], $registry);
    [$request, $context] = makeExecutionPair($this, outcomePolicyKey: 'callback');
    $result = $executor->execute($request, $context);

    expect($result)->toBeInstanceOf(ExecutionBlocked::class)
        ->and($result->reason)->toBe(BlockedReason::ConfigurationHold)
        ->and($this->dispatched)->toHaveCount(1)
        ->and($seen->routeId->value)->toBe($this->dispatched[0]->snapshot->routeId->value)
        ->and($seen->physicalAttemptId->value)->toBe($this->dispatched[0]->wire->physicalAttemptId->value)
        ->and($seen->origin->value())->toBe($request->url->origin->value());
});

it('сохраняет 429 cooldown при исключении политики и не обходит его новым запросом или большим burst', function (): void {
    $registry = new OutcomePolicyRegistry;
    $registry->register('throwing', static function (): never {
        throw new RuntimeException('fixture policy error');
    });
    $executor = makeExecutorForTest($this, [deliveredHttp(429, headers: ['retry-after' => ['120']])], $registry);
    [$request, $context] = makeExecutionPair($this, outcomePolicyKey: 'throwing');
    $result = $executor->execute($request, $context);
    $cooldown = $this->table('budgets')->where('scope_key', $this->scopeKey)->value('cooldown_until');
    expect($result)->toBeInstanceOf(ExecutionBlocked::class)
        ->and($this->table('attempts')->where('operation_id', $request->operationId->value)->value('status'))->toBe('completed');

    $this->table('budgets')->update(['burst' => 1_000_000, 'emission_interval_us' => 1_000_000]);
    [$next, $nextContext] = makeExecutionPair($this);
    $second = makeExecutorForTest($this, [deliveredHttp(200)])->execute($next, $nextContext);
    expect($second)->toBeInstanceOf(ExecutionDeferred::class)
        ->and($second->reason)->toBe(DeferralReason::RateLimit)
        ->and($second->retryAt->value->format('U.u'))->toBe((new DateTimeImmutable($cooldown))->format('U.u'))
        ->and($this->dispatched)->toHaveCount(1)
        ->and($this->table('permits')->where('operation_id', $next->operationId->value)->count())->toBe(0);

    $this->rejectedSql(fn () => $this->table('budgets')->update(['cooldown_until' => 'infinity']));
});

it('продлевает исходный origin cooldown при устаревшем epoch без атрибуции новому egress', function (): void {
    $executor = makeExecutorForTest($this, [function (): AttemptResult {
        $this->table('route_health')->increment('health_epoch');
        $this->table('routes')->where('id', $this->catalog['route']->value)->update(['egress_key' => 'nat:rotated', 'revision' => 2]);

        return deliveredHttp(429, headers: ['retry-after' => ['90']]);
    }]);
    [$request, $context] = makeExecutionPair($this);
    $result = $executor->execute($request, $context);
    $snapshot = json_decode($this->table('attempts')->where('operation_id', $request->operationId->value)->value('snapshot'), true);
    expect($result)->toBeInstanceOf(ExecutionDeferred::class)
        ->and($snapshot['egress_key'])->toBe('nat:fixture')
        ->and($snapshot['policy_revision'])->toBeGreaterThan(0)
        ->and(new DateTimeImmutable($this->table('budgets')->where('scope_key', $this->scopeKey)->value('cooldown_until')))->toBeGreaterThan(new DateTimeImmutable('+80 seconds'))
        ->and($this->table('observation_applications')->count())->toBe(4);
});

it('повторно читает текущую политику перед retry и не расширяет запрет direct', function (): void {
    $executor = makeExecutorForTest($this, [function (): AttemptResult {
        config(['proxyma.policy.project_defaults' => array_replace(config('proxyma.policy.project_defaults'), ['route.allow_direct' => false])]);

        return deliveredHttp(503);
    }, deliveredHttp(200)]);
    [$request, $context] = makeExecutionPair($this);
    $result = $executor->execute($request, $context);
    expect($result)->toBeInstanceOf(ExecutionBlocked::class)
        ->and($result->reason)->toBe(BlockedReason::DirectRouteNotAllowed)
        ->and($this->dispatched)->toHaveCount(1);
});

it('списывает общую квоту за каждый retry и не выдаёт permit сверх лимита', function (): void {
    $budget = $this->table('budgets')->where('scope_key', $this->scopeKey)->first();
    $this->app->make(QuotaService::class)->configure(
        $this->tenant, new Uuid($budget->id), 2, null, (int) $budget->revision);
    $executor = makeExecutorForTest($this, [deliveredHttp(503), deliveredHttp(503), deliveredHttp(200)]);
    [$request, $context] = makeExecutionPair($this);
    $result = $executor->execute($request, $context);
    $window = $this->table('quota_windows')->where('budget_id', $budget->id)->where('window_kind', 'hour')
        ->whereRaw('starts_at <= clock_timestamp() AND ends_at > clock_timestamp()')->first();
    expect($result)->toBeInstanceOf(ExecutionDeferred::class)
        ->and($result->reason)->toBe(DeferralReason::QuotaWindowExhausted)
        ->and($this->dispatched)->toHaveCount(2)
        ->and($this->table('permits')->where('operation_id', $request->operationId->value)->count())->toBe(2)
        ->and((int) $window->spent)->toBe(2)
        ->and((int) $window->reserved)->toBe(0)
        ->and((int) $this->table('budgets')->where('id', $budget->id)->value('inflight'))->toBe(0);
});

it('отклоняет новый distinct route под operation lock даже при прямом вызове claim', function (): void {
    $extra = selectionAddRoutes($this->db, $this->tenant, $this->catalog['profile'], $this->catalog['target'], 1);
    $origin = CanonicalOrigin::of('https', 'example.test');
    $this->budget($origin->routeScopeKey($extra[0]));
    $this->budget($origin->egressScopeKey('pool:shared'));
    $this->table('budgets')->update(['burst' => 100, 'max_inflight' => 100]);
    $intent = $this->demandOperation();
    $intent['request'] = $this->modifiedWire($intent['request'], [
        'timeouts' => new RequestTimeoutPolicy(1, 1, 1, 0, maxAttempts: 3, maxDistinctRoutes: 1),
    ]);
    [$permit, $input, $wire] = $this->prepareClaim($intent);
    $this->emit($this->claims->activate($this->claims->claim($permit, $input, $wire)));

    $route = $this->app->make(CatalogQueries::class)->findRoute($this->tenant, $extra[0]);
    $intent['request'] = $this->modifiedWire($intent['request'], ['physicalAttemptId' => Uuid::random()]);
    $intent['route'] = new RouteSelection($route->id, $route->kind, $this->catalog['profile'], null, 1, $route->egress);
    [$second, $input2, $wire2] = $this->prepareClaim($intent);
    expect(fn () => $this->claims->claim($second, $input2, $wire2))->toThrow(AdmissionRejected::class, 'distinct_route_limit');
    $this->reservations->cancel($this->tenant, $intent['operation']->operationId, $second->permitId);
    expect($this->physical->sends)->toBe(1)
        ->and($this->table('permits')->where('id', $second->permitId->value)->value('state'))->toBe('cancelled_before_send')
        ->and($this->table('attempts')->where('operation_id', $intent['operation']->operationId->value)->count())->toBe(1);
});

it('F1 сохраняет WAF cooldown при custom retry неоднозначной записи', function (CallerOutcomeAction $retry): void {
    $registry = new OutcomePolicyRegistry;
    $registry->register('custom-waf', static fn (): CallerOutcomeAction => $retry, wafRateSignal: true);
    $executor = makeExecutorForTest($this, [deliveredHttp(403), deliveredHttp(200)], $registry);
    [$request, $context] = makeExecutionPair($this, method: 'POST', idempotency: IdempotencyClass::AmbiguousWrite,
        body: WireBody::fromString('fixture-write'), outcomePolicyKey: 'custom-waf');

    $result = $executor->execute($request, $context);

    expect($result)->toBeInstanceOf(ExecutionDeferred::class)
        ->and($result->reason)->toBe(DeferralReason::TargetCooldown)
        ->and($result->retryAt->isAfter(new DateTimeImmutable))->toBeTrue()
        ->and($this->dispatched)->toHaveCount(1)
        ->and($this->table('permits')->where('operation_id', $request->operationId->value)->count())->toBe(1)
        ->and($this->table('route_target_health')->where('route_id', $this->catalog['route']->value)
            ->where('target_id', $request->targetId->value)->value('reason_code'))->toBe('waf_rate_signal');
})->with([CallerOutcomeAction::RetrySameRoute, CallerOutcomeAction::RetryAnotherRoute]);

it('F1 блокирует custom retry доставленного 502/503/504 с невоспроизводимым телом', function (int $status, CallerOutcomeAction $retry): void {
    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, 'one-shot-payload');
    rewind($stream);
    $body = WireBody::fromNonReplayableStream($stream);
    fclose($stream);
    $registry = new OutcomePolicyRegistry;
    $registry->register('custom-retry', static fn (): CallerOutcomeAction => $retry);
    $executor = makeExecutorForTest($this, [deliveredHttp($status), deliveredHttp(200)], $registry);
    [$request, $context] = makeExecutionPair($this, method: 'POST', idempotency: IdempotencyClass::IdempotentWrite,
        body: $body, outcomePolicyKey: 'custom-retry');

    $result = $executor->execute($request, $context);

    expect($result)->toBeInstanceOf(ExecutionBlocked::class)
        ->and($result->reason)->toBe(BlockedReason::PolicyReview)
        ->and($this->dispatched)->toHaveCount(1)
        ->and($this->table('permits')->where('operation_id', $request->operationId->value)->count())->toBe(1)
        ->and($this->table('attempts')->where('operation_id', $request->operationId->value)->value('status'))->toBe('completed');
})->with([502, 503, 504])->with([CallerOutcomeAction::RetrySameRoute, CallerOutcomeAction::RetryAnotherRoute]);

it('F2 видит credential hold разрешённого shared proxy под его owner tenant', function (): void {
    $owner = new TenantKey('proxy-owner');
    $endpoint = Uuid::random();
    $route = Uuid::random();
    $this->useTenant($owner);
    $this->table('endpoints')->insert(['id' => $endpoint->value, 'tenant_key' => $owner->value,
        'protocol' => 'http', 'host' => 'proxy.test', 'port' => 8080, 'credential_ref' => null, 'credential_version' => null]);
    $this->commands()->upsertRoute(new UpsertRouteCommand($route, $owner, 'shared-proxy', RouteKind::Proxy, $endpoint, true,
        EgressIdentity::explicit('nat:shared'), null, false, $this->actor()));
    $this->app->make(SharedRouteCommands::class)->shareRoute(new ShareRouteCommand(Uuid::random(), $owner, $this->tenant, $route, $this->actor()));
    $this->table('route_health')->where('tenant_key', $owner->value)->where('route_id', $route->value)
        ->update(['state' => 'open', 'reason_code' => 'credential_hold:1']);
    $this->useTenant($this->tenant);
    $this->commands()->upsertProfile(new UpsertRouteProfileCommand($this->catalog['profile'], $this->tenant, 'default', [$route],
        'uniform_random', $this->actor(1)));
    $prepared = $this->app->make(CatalogQueries::class)->routesForProfile($this->tenant, $this->catalog['profile'])[0];
    expect($prepared->tenantKey->value)->toBe($owner->value)->and($prepared->credentialVersion)->toBe(1);
    $executor = makeExecutorForTest($this, [deliveredHttp(200)]);
    [$request, $context] = makeExecutionPair($this);

    $result = $executor->execute($request, $context);

    expect($result)->toBeInstanceOf(ExecutionBlocked::class)
        ->and($result->reason)->toBe(BlockedReason::CredentialHold)
        ->and($this->dispatched)->toBe([])
        ->and($this->table('permits')->where('operation_id', $request->operationId->value)->count())->toBe(0);
});

it('F2 сохраняет credential hold после 407 при NULL initial credential version', function (bool $delivered): void {
    $endpoint = Uuid::random();
    $this->table('endpoints')->insert(['id' => $endpoint->value, 'tenant_key' => $this->tenant->value,
        'protocol' => 'http', 'host' => 'proxy.test', 'port' => 8080, 'credential_ref' => null, 'credential_version' => null]);
    $this->table('routes')->where('id', $this->catalog['route']->value)
        ->update(['kind' => 'proxy', 'endpoint_id' => $endpoint->value, 'revision' => 2]);
    $attempt = $delivered ? deliveredHttp(407) : AttemptResult::failed(TransportFailure::ProxyAuth);
    $executor = makeExecutorForTest($this, [$attempt, deliveredHttp(200)]);
    [$request, $context] = makeExecutionPair($this);

    $result = $executor->execute($request, $context);

    expect($result)->toBeInstanceOf(ExecutionBlocked::class)
        ->and($result->reason)->toBe(BlockedReason::CredentialHold)
        ->and($this->dispatched)->toHaveCount(1)
        ->and($this->dispatched[0]->snapshot->credentialVersion)->toBe(1)
        ->and($this->table('endpoints')->where('id', $endpoint->value)->value('credential_version'))->toBeNull()
        ->and($this->table('route_health')->where('route_id', $this->catalog['route']->value)->value('reason_code'))->toBe('credential_hold:1');
    [$nextRequest, $nextContext] = makeExecutionPair($this);
    $next = $executor->execute($nextRequest, $nextContext);
    expect($next)->toBeInstanceOf(ExecutionBlocked::class)
        ->and($next->reason)->toBe(BlockedReason::CredentialHold)
        ->and($this->dispatched)->toHaveCount(1)
        ->and($this->table('permits')->where('operation_id', $nextRequest->operationId->value)->count())->toBe(0);
})->with(['delivered 407' => [true], 'proxy auth transport failure' => [false]]);
