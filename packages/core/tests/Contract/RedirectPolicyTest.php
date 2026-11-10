<?php

declare(strict_types=1);

use Proxyma\Application\Execution\DestinationPolicy;
use Proxyma\Application\Execution\OutboundExecutor;
use Proxyma\Application\Execution\OutcomePolicyRegistry;
use Proxyma\Application\Execution\ProxyResolver;
use Proxyma\Application\Policy\PolicyResolver;
use Proxyma\Application\Recovery\CompletionCoordinator;
use Proxyma\Application\Routing\RouteSelector;
use Proxyma\Contracts\CatalogQueries;
use Proxyma\Contracts\PhysicalTransport;
use Proxyma\Contracts\SecretResolver;
use Proxyma\Contracts\TlsTrustPolicy;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\CredentialRef;
use Proxyma\Domain\Catalog\UpsertTargetCommand;
use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\AuthorizedWireRequest;
use Proxyma\Domain\Execution\BlockedReason;
use Proxyma\Domain\Execution\Duration;
use Proxyma\Domain\Execution\ExecutionBlocked;
use Proxyma\Domain\Execution\ExecutionContext;
use Proxyma\Domain\Execution\ExecutionSucceeded;
use Proxyma\Domain\Execution\ExecutionDeferred;
use Proxyma\Domain\Execution\DeferralReason;
use Proxyma\Domain\Execution\IdempotencyClass;
use Proxyma\Domain\Execution\OperationCorrelation;
use Proxyma\Domain\Execution\OutboundRequest;
use Proxyma\Domain\Execution\OwnerRef;
use Proxyma\Domain\Execution\PreparedHeaders;
use Proxyma\Domain\Execution\RawResponseBody;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\ResolvedSecret;
use Proxyma\Domain\Execution\ResponseDescriptor;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Execution\TlsTrustConfiguration;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireBody;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Http\DnsResolver;
use Proxyma\Infrastructure\Http\PhysicalHttpTransport;
use Proxyma\Infrastructure\Http\TlsTrust;
use Proxyma\Tests\Support\CatalogTestCase;
use Proxyma\Tests\Support\ClaimFixtures;
use Proxyma\Tests\Support\DemandFixtures;
use Proxyma\Tests\Support\PhysicalFixtures;
use Proxyma\Tests\Support\QuotaFixtures;
use Proxyma\Tests\Support\ReservationFixtures;
use Tests\Support\WireServer;

foreach (['CatalogTestCase', 'SelectionFixtures', 'DemandFixtures', 'ReservationFixtures', 'ClaimFixtures', 'QuotaFixtures', 'PhysicalFixtures'] as $file) {
    require_once __DIR__.'/../Support/'.$file.'.php';
}

uses(CatalogTestCase::class, DemandFixtures::class, ReservationFixtures::class, ClaimFixtures::class, QuotaFixtures::class, PhysicalFixtures::class);

beforeEach(function (): void {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    config(['proxyma.policy.project_defaults' => [
        'route.allow_direct' => true,
        'retry.max_attempts' => 10,
        'http.max_redirects' => 10,
    ]]);
    $this->initializeClaimFixture();
    $this->table('budgets')->update(['burst' => 100, 'max_inflight' => 100, 'emission_interval_us' => 1]);
    $this->dispatched = [];
    $this->socket = null;
    $this->dnsHosts = [];
});

afterEach(function (): void {
    $this->socket?->stop();
});

/**
 * @param  list<AttemptResult|(Closure(AuthorizedWireRequest, int): AttemptResult)>  $script
 * @param  array<string, array<string, list<string>>>  $privateTargets
 * @param  array<string, list<string>>  $allowedRedirectOrigins
 * @param  array<string, list<string>>  $dnsRecords
 */
function makeRedirectExecutor(
    object $test,
    array $script,
    array $privateTargets = [],
    array $allowedRedirectOrigins = [],
    array $dnsRecords = [
        'example.test' => ['93.184.216.34'],
        'partner.test' => ['93.184.216.35'],
        'private.test' => ['10.0.0.42'],
        'metadata.test' => ['169.254.169.254'],
    ],
): OutboundExecutor {
    return (function () use ($script, $privateTargets, $allowedRedirectOrigins, $dnsRecords): OutboundExecutor {
        $scope = $this->transactions->scope;
        $dns = new DnsResolver($scope, function (string $host) use ($dnsRecords): array {
            $this->dnsHosts[] = $host;

            return $dnsRecords[$host] ?? ['93.184.216.34'];
        });
        $destinations = new DestinationPolicy($dns, $privateTargets, [80, 443, 8443], $allowedRedirectOrigins);
        $proxies = new ProxyResolver($dns);

        $secretResolver = new class implements SecretResolver
        {
            public function resolve(CredentialRef $credential, TenantKey $tenant): ?ResolvedSecret
            {
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

        $registry = new OutcomePolicyRegistry;
        $registry->freeze();

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
        );
    })->call($test);
}

function redirectResponse(int $status, string $location, string $body = ''): AttemptResult
{
    $len = strlen($body);

    return AttemptResult::delivered(new ResponseDescriptor(
        $status,
        ['location' => [$location], 'content-type' => ['text/plain']],
        new RawResponseBody($body),
        $len,
        $len,
    ));
}

function makeRedirectRequestPair(
    object $test,
    string $method = 'GET',
    string $url = 'https://example.test/start',
    ?RequestTimeoutPolicy $timeouts = null,
    IdempotencyClass $idempotency = IdempotencyClass::ReadOnly,
    ?PreparedHeaders $headers = null,
    ?WireBody $body = null,
    ?Uuid $targetId = null,
): array {
    return (function () use ($method, $url, $timeouts, $idempotency, $headers, $body, $targetId): array {
        $operationId = Uuid::random();
        $owner = new OwnerRef($this->tenant, 'standalone', $operationId->value, 'fixture-token');
        $operation = new OperationCorrelation(
            operationId: $operationId,
            owner: $owner,
            deadlineAt: new DateTimeImmutable('+2 minutes'),
            externalOperationKey: 'redir:'.$operationId->value,
        );
        $subjects = new SubjectChain(new SubjectRef($this->tenant, 'document', 'fixture'));
        $context = new ExecutionContext(
            operation: $operation,
            subjects: $subjects,
            remainingSlice: new Duration(60_000),
            monotonicDeadlineNs: hrtime(true) + 60_000_000_000,
        );
        $request = new OutboundRequest(
            operationId: $operationId,
            targetId: $targetId ?? $this->catalog['target'],
            method: $method,
            url: new SafeUrl($url),
            timeouts: $timeouts ?? new RequestTimeoutPolicy(500, 1000, 2000, 1000, maxAttempts: 6, maxDistinctRoutes: 2, maxRedirects: 3),
            idempotency: $idempotency,
            headers: $headers ?? new PreparedHeaders,
            body: $body,
        );

        return [$request, $context];
    })->call($test);
}

it('выдаёт новый permit, новый claim и заново запечатывает wire descriptor на каждом шаге редиректа (включая реальный loopback сокет)', function (): void {
    $executor = makeRedirectExecutor($this, [
        redirectResponse(302, '/hop-1?step=1#ignored-fragment'),
        redirectResponse(302, 'sub/hop-2'),
        AttemptResult::delivered(new ResponseDescriptor(200, ['content-type' => ['text/plain']], new RawResponseBody('final-hop'), 9, 9)),
    ]);

    [$request, $context] = makeRedirectRequestPair($this, url: 'https://example.test/dir/start');
    $outcome = $executor->execute($request, $context);

    expect($outcome)->toBeInstanceOf(ExecutionSucceeded::class)
        ->and($outcome->response->statusCode)->toBe(200)
        ->and($outcome->response->body->bytes())->toBe('final-hop')
        ->and($outcome->attemptIds)->toHaveCount(3)
        ->and($this->dispatched)->toHaveCount(3);

    $urls = array_map(static fn (AuthorizedWireRequest $req): string => $req->wire->url->reveal(), $this->dispatched);
    $permitIds = array_map(static fn (AuthorizedWireRequest $req): string => $req->snapshot->permitId->value, $this->dispatched);
    $fingerprints = array_map(static fn (AuthorizedWireRequest $req): string => $req->snapshot->fullFingerprint->hex, $this->dispatched);

    expect($urls)->toBe([
        'https://example.test/dir/start',
        'https://example.test/hop-1?step=1',
        'https://example.test/sub/hop-2',
    ])->and(array_unique($permitIds))->toHaveCount(3)
        ->and(array_unique($fingerprints))->toHaveCount(3)
        ->and($this->table('permits')->where('operation_id', $request->operationId->value)->where('state', 'completed')->count())->toBe(3)
        ->and($this->table('attempts')->where('operation_id', $request->operationId->value)->where('status', 'completed')->count())->toBe(3);

    // Также проверяем реальный локальный сокет WireServer + PhysicalHttpTransport (/redirect -> 302 Location: /ok -> 200 OK).
    $this->socket = new WireServer;
    $wireUrl = new SafeUrl($this->socket->url('/redirect', 'tls'));
    $wireTargetId = Uuid::random();
    $this->commands()->upsertTarget(new UpsertTargetCommand(
        $wireTargetId,
        $this->tenant,
        'wire-redir-target',
        $wireUrl->origin,
        'default',
        $this->actor(),
    ));
    selectionHealth($this->db, $this->tenant);
    $route = $this->app->make(CatalogQueries::class)->findRoute($this->tenant, $this->catalog['route']);
    foreach ([$wireUrl->origin->budgetScopeKey(), $wireUrl->origin->routeScopeKey($route->id), $wireUrl->origin->egressScopeKey($route->egress->key)] as $scopeKey) {
        if (! $this->table('budgets')->where('scope_key', $scopeKey)->exists()) {
            $this->budget($scopeKey);
        }
    }
    $this->table('budgets')->update(['burst' => 100, 'max_inflight' => 100, 'emission_interval_us' => 1]);

    $scope = $this->transactions->scope;
    $dns = new DnsResolver($scope, static fn (): array => ['127.0.0.1']);
    $destinations = new DestinationPolicy(
        $dns,
        [$wireTargetId->value => ['origin.test' => ['127.0.0.1']]],
        [$wireUrl->origin->port],
    );
    $trust = new TlsTrust($scope, ['origin.test' => $this->socket->certificates.'/ca.pem'], []);
    $realExecutor = new OutboundExecutor(
        scope: $scope,
        catalog: $this->app->make(CatalogQueries::class),
        policies: $this->app->make(PolicyResolver::class),
        destinations: $destinations,
        selector: $this->app->make(RouteSelector::class),
        proxies: new ProxyResolver($dns),
        secrets: $this->app->make(SecretResolver::class),
        tlsTrust: $trust,
        sealer: $this->sealer,
        operations: $this->operations,
        reservations: $this->reservations,
        claims: $this->claims,
        transport: new PhysicalHttpTransport($scope, $this->sealer),
        outcomes: new OutcomePolicyRegistry,
        completion: $this->app->make(CompletionCoordinator::class),
    );

    [$realReq, $realCtx] = makeRedirectRequestPair($this, url: $wireUrl->reveal(), targetId: $wireTargetId);
    $realOutcome = $realExecutor->execute($realReq, $realCtx);

    expect($realOutcome)->toBeInstanceOf(ExecutionSucceeded::class)
        ->and($realOutcome->response->statusCode)->toBe(200)
        ->and($realOutcome->response->body->bytes())->toBe('fixture-response')
        ->and($realOutcome->attemptIds)->toHaveCount(2)
        ->and($this->socket->events('origin'))->toHaveCount(2);
});

it('соблюдает правила 303 (переход в GET без тела) и 307/308 (сохранение метода и только воспроизводимого тела)', function (): void {
    // 1) 303 See Other переводит POST с телом в GET без тела и очищает Content-Type / Content-Length.
    $executor303 = makeRedirectExecutor($this, [
        redirectResponse(303, 'https://example.test/result'),
        AttemptResult::delivered(new ResponseDescriptor(200, ['content-type' => ['text/plain']], new RawResponseBody('done-303'), 8, 8)),
    ]);

    [$req303, $ctx303] = makeRedirectRequestPair(
        $this,
        method: 'POST',
        url: 'https://example.test/submit',
        idempotency: IdempotencyClass::AmbiguousWrite,
        headers: new PreparedHeaders(['Content-Type' => 'application/json', 'X-Trace' => 'trace-1']),
        body: WireBody::fromString('{"submit":true}'),
    );
    $res303 = $executor303->execute($req303, $ctx303);

    expect($res303)->toBeInstanceOf(ExecutionSucceeded::class)
        ->and($this->dispatched)->toHaveCount(2);

    $firstHop = $this->dispatched[0]->wire;
    $secondHop = $this->dispatched[1]->wire;
    expect($firstHop->method)->toBe('POST')
        ->and($firstHop->body?->bytes())->toBe('{"submit":true}')
        ->and($secondHop->method)->toBe('GET')
        ->and($secondHop->body)->toBeNull()
        ->and($secondHop->idempotency)->toBe(IdempotencyClass::ReadOnly)
        ->and($secondHop->headers->reveal())->not->toHaveKey('content-type')
        ->and($secondHop->headers->reveal())->not->toHaveKey('content-length')
        ->and($secondHop->headers->reveal()['x-trace'] ?? null)->toBe('trace-1');

    // 2) 307 и 308 сохраняют метод POST/PUT и воспроизводимое тело при IdempotentWrite.
    foreach ([307, 308] as $code) {
        $this->dispatched = [];
        $executorPreserve = makeRedirectExecutor($this, [
            redirectResponse($code, 'https://example.test/v2/upload'),
            AttemptResult::delivered(new ResponseDescriptor(200, ['content-type' => ['text/plain']], new RawResponseBody('uploaded-'.$code), 12, 12)),
        ]);

        [$reqPreserve, $ctxPreserve] = makeRedirectRequestPair(
            $this,
            method: 'POST',
            url: 'https://example.test/v1/upload',
            idempotency: IdempotencyClass::IdempotentWrite,
            headers: new PreparedHeaders(['Content-Type' => 'application/json', 'Idempotency-Key' => 'idem-'.$code]),
            body: WireBody::fromString('{"replayable":1}'),
        );
        $resPreserve = $executorPreserve->execute($reqPreserve, $ctxPreserve);

        expect($resPreserve)->toBeInstanceOf(ExecutionSucceeded::class)
            ->and($this->dispatched)->toHaveCount(2)
            ->and($this->dispatched[1]->wire->method)->toBe('POST')
            ->and($this->dispatched[1]->wire->body?->bytes())->toBe('{"replayable":1}')
            ->and($this->dispatched[1]->wire->headers->reveal()['content-type'] ?? null)->toBe('application/json');
    }

    // 3) 307/308 с невоспроизводимым потоковым телом блокируется до второго шага.
    $this->dispatched = [];
    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, 'one-shot-stream-payload');
    rewind($stream);
    $nonReplayableBody = WireBody::fromNonReplayableStream($stream);
    fclose($stream);

    $executorNonReplayable = makeRedirectExecutor($this, [
        redirectResponse(307, 'https://example.test/v2/stream'),
        AttemptResult::delivered(new ResponseDescriptor(200, ['content-type' => ['text/plain']], new RawResponseBody('must-not-run'), 12, 12)),
    ]);
    [$reqStream, $ctxStream] = makeRedirectRequestPair(
        $this,
        method: 'PUT',
        url: 'https://example.test/v1/stream',
        idempotency: IdempotencyClass::IdempotentWrite,
        body: $nonReplayableBody,
    );
    $resStream = $executorNonReplayable->execute($reqStream, $ctxStream);

    expect($resStream)->toBeInstanceOf(ExecutionBlocked::class)
        ->and($resStream->reason)->toBe(BlockedReason::PolicyReview)
        ->and($this->dispatched)->toHaveCount(1);

    // 4) 301/302 для POST с телом блокируется как неоднозначная перезапись метода.
    $this->dispatched = [];
    $executor302Post = makeRedirectExecutor($this, [
        redirectResponse(302, 'https://example.test/next'),
        AttemptResult::delivered(new ResponseDescriptor(200, ['content-type' => ['text/plain']], new RawResponseBody('must-not-run'), 12, 12)),
    ]);
    [$req302Post, $ctx302Post] = makeRedirectRequestPair(
        $this,
        method: 'POST',
        url: 'https://example.test/form',
        idempotency: IdempotencyClass::IdempotentWrite,
        body: WireBody::fromString('body'),
    );
    $res302Post = $executor302Post->execute($req302Post, $ctx302Post);

    expect($res302Post)->toBeInstanceOf(ExecutionBlocked::class)
        ->and($res302Post->reason)->toBe(BlockedReason::PolicyReview)
        ->and($this->dispatched)->toHaveCount(1);
});

it('блокирует циклы редиректов, превышение maxRedirects, HTTPS->HTTP downgrade и приватные или неразрешённые цели', function (): void {
    // 1) Обнаружение цикла редиректов (A -> B -> A).
    $this->dispatched = [];
    $executorCycle = makeRedirectExecutor($this, [
        redirectResponse(302, 'https://example.test/hop-b'),
        redirectResponse(302, 'https://example.test/start'),
        AttemptResult::delivered(new ResponseDescriptor(200, ['content-type' => ['text/plain']], new RawResponseBody('unreachable'), 11, 11)),
    ]);
    [$reqCycle, $ctxCycle] = makeRedirectRequestPair($this, url: 'https://example.test/start');
    $resCycle = $executorCycle->execute($reqCycle, $ctxCycle);

    expect($resCycle)->toBeInstanceOf(ExecutionBlocked::class)
        ->and($resCycle->reason)->toBe(BlockedReason::UrlPolicyDenied)
        ->and($resCycle->safeDetails['redirect_reason'] ?? null)->toBe('redirect_cycle')
        ->and($this->dispatched)->toHaveCount(2);

    // 2) Превышение maxRedirects = 2 останавливает цепочку на 3-м редиректе.
    $this->dispatched = [];
    $executorMax = makeRedirectExecutor($this, [
        redirectResponse(302, 'https://example.test/r1'),
        redirectResponse(302, 'https://example.test/r2'),
        redirectResponse(302, 'https://example.test/r3'),
        AttemptResult::delivered(new ResponseDescriptor(200, ['content-type' => ['text/plain']], new RawResponseBody('unreachable'), 11, 11)),
    ]);
    [$reqMax, $ctxMax] = makeRedirectRequestPair(
        $this,
        url: 'https://example.test/r0',
        timeouts: new RequestTimeoutPolicy(500, 1000, 2000, 1000, maxAttempts: 4, maxDistinctRoutes: 1, maxRedirects: 2),
    );
    $resMax = $executorMax->execute($reqMax, $ctxMax);

    expect($resMax)->toBeInstanceOf(ExecutionBlocked::class)
        ->and($resMax->reason)->toBe(BlockedReason::UrlPolicyDenied)
        ->and($resMax->safeDetails['redirect_reason'] ?? null)->toBe('max_redirects_exceeded')
        ->and($this->dispatched)->toHaveCount(3);

    // 3) Запрет понижения схемы HTTPS -> HTTP.
    $this->dispatched = [];
    $executorDowngrade = makeRedirectExecutor($this, [
        redirectResponse(302, 'http://example.test/insecure'),
        AttemptResult::delivered(new ResponseDescriptor(200, ['content-type' => ['text/plain']], new RawResponseBody('unreachable'), 11, 11)),
    ]);
    [$reqDown, $ctxDown] = makeRedirectRequestPair($this, url: 'https://example.test/secure');
    $resDown = $executorDowngrade->execute($reqDown, $ctxDown);

    expect($resDown)->toBeInstanceOf(ExecutionBlocked::class)
        ->and($resDown->reason)->toBe(BlockedReason::UrlPolicyDenied)
        ->and($resDown->safeDetails['redirect_reason'] ?? null)->toBe('https_to_http_downgrade')
        ->and($this->dispatched)->toHaveCount(1);

    // 4) Запрет редиректа на приватный IP / metadata / неразрешённый внешний origin.
    foreach ([
        'https://private.test/internal',
        'https://metadata.test/latest/meta-data',
        'https://partner.test/unapproved-cross-origin',
    ] as $forbiddenLocation) {
        $this->dispatched = [];
        $executorForbidden = makeRedirectExecutor($this, [
            redirectResponse(302, $forbiddenLocation),
            AttemptResult::delivered(new ResponseDescriptor(200, ['content-type' => ['text/plain']], new RawResponseBody('unreachable'), 11, 11)),
        ], allowedRedirectOrigins: [$this->catalog['target']->value => ['https://private.test:443', 'https://metadata.test:443']]);
        [$reqForb, $ctxForb] = makeRedirectRequestPair($this, url: 'https://example.test/public');
        $resForb = $executorForbidden->execute($reqForb, $ctxForb);

        expect($resForb)->toBeInstanceOf(ExecutionBlocked::class)
            ->and($resForb->reason)->toBe(BlockedReason::UrlPolicyDenied)
            ->and($this->dispatched)->toHaveCount(1);
    }
});

it('удаляет секретные заголовки при кросс-оригин редиректе и сохраняет их внутри одного origin', function (): void {
    $sensitiveHeaders = new PreparedHeaders([
        'Authorization' => 'Bearer top-secret-token',
        'Cookie' => 'session=secret-cookie',
        'X-Api-Key' => 'api-key-123',
        'Api-Key' => 'api-key-456',
        'X-Auth-Token' => 'auth-token-789',
        'Content-Type' => 'application/json',
        'X-Correlation-Id' => 'corr-42',
    ]);

    // 1) Same-origin редирект сохраняет чувствительные заголовки.
    $this->dispatched = [];
    $executorSameOrigin = makeRedirectExecutor($this, [
        redirectResponse(307, 'https://example.test/same-origin-hop'),
        AttemptResult::delivered(new ResponseDescriptor(200, ['content-type' => ['text/plain']], new RawResponseBody('same-ok'), 7, 7)),
    ]);
    [$reqSame, $ctxSame] = makeRedirectRequestPair(
        $this,
        method: 'POST',
        url: 'https://example.test/initial',
        idempotency: IdempotencyClass::IdempotentWrite,
        headers: $sensitiveHeaders,
        body: WireBody::fromString('{"k":"v"}'),
    );
    $resSame = $executorSameOrigin->execute($reqSame, $ctxSame);

    expect($resSame)->toBeInstanceOf(ExecutionSucceeded::class)
        ->and($this->dispatched)->toHaveCount(2);
    $sameHopHeaders = $this->dispatched[1]->wire->headers->reveal();
    expect($sameHopHeaders)->toHaveKey('authorization')
        ->and($sameHopHeaders)->toHaveKey('cookie')
        ->and($sameHopHeaders)->toHaveKey('x-api-key')
        ->and($sameHopHeaders['x-correlation-id'] ?? null)->toBe('corr-42');

    $partner = CanonicalOrigin::of('https', 'partner.test');
    $this->budget($partner->budgetScopeKey());
    $this->budget($partner->routeScopeKey($this->catalog['route']));
    $this->budget($partner->egressScopeKey('nat:fixture'));
    $this->table('budgets')->update(['burst' => 100, 'max_inflight' => 100]);

    // 2) Cross-origin редирект (на явно разрешённый partner.test) удаляет все креды, но сохраняет безопасные заголовки и Content-Type при 307 с телом.
    $this->dispatched = [];
    $executorCrossOrigin = makeRedirectExecutor(
        $this,
        [
            redirectResponse(307, 'https://partner.test/cross-origin-hop'),
            AttemptResult::delivered(new ResponseDescriptor(200, ['content-type' => ['text/plain']], new RawResponseBody('cross-ok'), 8, 8)),
        ],
        allowedRedirectOrigins: [
            $this->catalog['target']->value => ['https://partner.test', 'partner.test'],
        ],
    );
    [$reqCross, $ctxCross] = makeRedirectRequestPair(
        $this,
        method: 'POST',
        url: 'https://example.test/initial',
        idempotency: IdempotencyClass::IdempotentWrite,
        headers: $sensitiveHeaders,
        body: WireBody::fromString('{"k":"v"}'),
    );
    $resCross = $executorCrossOrigin->execute($reqCross, $ctxCross);

    expect($resCross)->toBeInstanceOf(ExecutionSucceeded::class)
        ->and($this->dispatched)->toHaveCount(2);

    $crossHopHeaders = $this->dispatched[1]->wire->headers->reveal();
    expect($crossHopHeaders)->not->toHaveKey('authorization')
        ->and($crossHopHeaders)->not->toHaveKey('cookie')
        ->and($crossHopHeaders)->not->toHaveKey('proxy-authorization')
        ->and($crossHopHeaders)->not->toHaveKey('x-api-key')
        ->and($crossHopHeaders)->not->toHaveKey('api-key')
        ->and($crossHopHeaders)->not->toHaveKey('x-auth-token')
        ->and($crossHopHeaders['content-type'] ?? null)->toBe('application/json')
        ->and($crossHopHeaders)->not->toHaveKey('x-correlation-id');
});

it('считает redirect и retry в общем maxAttempts до следующего DNS и сохраняет durable лимит при повторном execute', function (): void {
    $executor = makeRedirectExecutor($this, [
        redirectResponse(302, '/hop-1'),
        redirectResponse(302, '/hop-2'),
        AttemptResult::delivered(new ResponseDescriptor(200, [], new RawResponseBody('unreachable'), 11, 11)),
    ]);
    [$request, $context] = makeRedirectRequestPair($this,
        timeouts: new RequestTimeoutPolicy(500, 1000, 2000, 1000, maxAttempts: 2, maxDistinctRoutes: 1, maxRedirects: 3));
    $result = $executor->execute($request, $context);
    expect($result)->toBeInstanceOf(ExecutionDeferred::class)
        ->and($result->reason)->toBe(DeferralReason::AttemptsExhausted)
        ->and($this->dnsHosts)->toHaveCount(2)
        ->and($this->dispatched)->toHaveCount(2)
        ->and((int) $this->table('operations')->where('id', $request->operationId->value)->value('authorized_attempts'))->toBe(2)
        ->and((int) $this->table('operations')->where('id', $request->operationId->value)->value('max_physical_attempts'))->toBe(2);

    $resumed = $executor->execute($request, $context);
    expect($resumed)->toBeInstanceOf(ExecutionDeferred::class)
        ->and($resumed->reason)->toBe(DeferralReason::AttemptsExhausted)
        ->and($this->dnsHosts)->toHaveCount(2)
        ->and($this->dispatched)->toHaveCount(2);
});

it('не создаёт отсутствующий бюджет разрешённого redirect origin и не отправляет второй hop', function (): void {
    $executor = makeRedirectExecutor($this, [redirectResponse(307, 'https://partner.test/hop')],
        allowedRedirectOrigins: [$this->catalog['target']->value => ['https://partner.test:443']]);
    [$request, $context] = makeRedirectRequestPair($this);
    $before = $this->table('budgets')->count();
    $result = $executor->execute($request, $context);
    expect($result)->toBeInstanceOf(ExecutionBlocked::class)
        ->and($result->reason)->toBe(BlockedReason::ConfigurationHold)
        ->and($this->dispatched)->toHaveCount(1)
        ->and($this->table('budgets')->count())->toBe($before)
        ->and($this->table('permits')->where('operation_id', $request->operationId->value)->count())->toBe(1);
});
