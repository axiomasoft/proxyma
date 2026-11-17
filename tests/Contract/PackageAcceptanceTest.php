<?php

declare(strict_types=1);

use Proxyma\Application\Execution\DestinationPolicy;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Application\Policy\SettingRegistry;
use Proxyma\Application\Recovery\RetentionPruner;
use Proxyma\Contracts\Execution\DestinationResolver;
use Proxyma\Contracts\Execution\OutboundClient;
use Proxyma\Contracts\Execution\PhysicalTransport;
use Proxyma\Contracts\Routing\RouteFilter;
use Proxyma\Contracts\Security\TlsTrustPolicy;
use Proxyma\Domain\Catalog\UpsertTargetCommand;
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
use Proxyma\Domain\Execution\IdempotencyClass;
use Proxyma\Domain\Execution\OperationCorrelation;
use Proxyma\Domain\Execution\OutboundRequest;
use Proxyma\Domain\Execution\OwnerRef;
use Proxyma\Domain\Execution\PreparedHeaders;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireBody;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Panels\ExtensionDefinition;
use Proxyma\Domain\Panels\ExtensionKind;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Http\DnsResolver;
use Proxyma\Infrastructure\Http\TlsTrust;
use Proxyma\Tests\Support\CatalogTestCase;
use Proxyma\Tests\Support\ClaimFixtures;
use Proxyma\Tests\Support\DemandFixtures;
use Proxyma\Tests\Support\PhysicalFixtures;
use Proxyma\Tests\Support\QuotaFixtures;
use Proxyma\Tests\Support\ReservationFixtures;
use Symfony\Component\Process\Process;

foreach (['CatalogTestCase', 'SelectionFixtures', 'DemandFixtures', 'ReservationFixtures', 'ClaimFixtures', 'QuotaFixtures', 'PhysicalFixtures'] as $file) {
    require_once dirname(__DIR__, 2).'/packages/core/tests/Support/'.$file.'.php';
}

uses(CatalogTestCase::class, DemandFixtures::class, ReservationFixtures::class, ClaimFixtures::class, QuotaFixtures::class, PhysicalFixtures::class)->group('pg');

final class AcceptanceRouteFilter implements RouteFilter
{
    public int $calls = 0;

    public function __construct(private bool $deny) {}

    public function filter(array $profileRoutes, WireRequest $request): array
    {
        $this->calls++;

        return $this->deny ? [] : $profileRoutes;
    }
}

beforeEach(function (): void {
    $this->reconfigureProxyma(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'policy.project_defaults' => ['route.allow_direct' => true]]);
    $this->initializePhysicalFixture();
});

afterEach(function (): void {
    $this->socket?->stop();
});

function acceptancePair(object $test, string $listener = 'tls', IdempotencyClass $idempotency = IdempotencyClass::ReadOnly): array
{
    return (function () use ($listener, $idempotency): array {
        $url = new SafeUrl($this->socket->url('/ok', $listener));
        $targetId = Uuid::random();
        $this->commands()->upsertTarget(new UpsertTargetCommand($targetId, $this->tenant,
            'acceptance-'.str_replace('-', '', $targetId->value), $url->origin, 'default', $this->actor()));
        $this->catalog['target'] = $targetId;
        selectionHealth($this->db, $this->tenant);
        foreach ([$url->origin->budgetScopeKey(), $url->origin->routeScopeKey($this->catalog['route']), $url->origin->egressScopeKey('nat:fixture')] as $key) {
            $this->budget($key);
        }
        $this->table('budgets')->update(['burst' => 100, 'max_inflight' => 100]);
        $this->originBudget = new Uuid($this->table('budgets')->where('scope_key', $url->origin->budgetScopeKey())->value('id'));
        $this->quota->configure($this->tenant, $this->originBudget, 20, 40, 1);
        $id = Uuid::random();
        $operation = new OperationCorrelation($id, new OwnerRef($this->tenant, 'standalone', $id->value, 'fixture-token'),
            new DateTimeImmutable('+1 minute'), 'acceptance:'.$id->value);
        $timeouts = new RequestTimeoutPolicy(500, 1000, 3000, 500, maxAttempts: 1);
        $this->operations->create($operation, $this->catalog['profile'], $targetId, $timeouts);
        $scope = $this->transactions->scope;
        $this->app->instance(DestinationResolver::class, new DestinationPolicy(
            new DnsResolver($scope, static fn (): array => ['127.0.0.1']),
            [$targetId->value => ['origin.test' => ['127.0.0.1']]],
            [$url->origin->port],
        ));
        $this->app->instance(TlsTrustPolicy::class, new TlsTrust($scope, ['origin.test' => $this->socket->certificates.'/ca.pem']));
        $request = new OutboundRequest($id, $targetId, 'POST', $url, $timeouts, idempotency: $idempotency,
            headers: new PreparedHeaders(['X-Fixture' => 'qualification']), body: WireBody::fromString('sealed-body'));
        $context = new ExecutionContext($operation, new SubjectChain(new SubjectRef($this->tenant, 'document', 'qualification')),
            new Duration(60000), hrtime(true) + 60000000000);

        return [$request, $context];
    })->call($test);
}

it('public send использует safe extension, новый permit, trusted TLS и один fixture receipt', function (bool $deny): void {
    [$request, $context] = acceptancePair($this);
    $filter = new AcceptanceRouteFilter($deny);
    $registry = new DefinitionRegistry($this->app->make(SettingRegistry::class));
    $registry->registerExtension(new ExtensionDefinition('acceptance.filter', ExtensionKind::RouteFilter, $filter));
    $registry->freeze();
    $this->app->instance(DefinitionRegistry::class, $registry);
    $this->reconfigureProxyma(['extensions.route_filters' => ['acceptance.filter']]);
    $result = $this->app->make(OutboundClient::class)->execute($request, $context);
    expect($filter->calls)->toBeGreaterThan(0);
    if ($deny) {
        expect($result)->toBeInstanceOf(ExecutionDeferred::class)
            ->and($result->reason)->toBe(DeferralReason::NoHealthyRoute)
            ->and($this->table('attempts')->count())->toBe(0)
            ->and((int) $this->table('quota_windows')->sum('spent'))->toBe(0);
        $this->socket->assertNoConnections();

        return;
    }
    expect($result)->toBeInstanceOf(ExecutionSucceeded::class)
        ->and($this->table('attempts')->where('status', 'completed')->count())->toBe(1)
        ->and($this->table('observations')->count())->toBe(1)
        ->and($this->table('permits')->whereNotNull('authorized_at')->count())->toBe(1)
        ->and((int) $this->table('budgets')->sum('inflight'))->toBe(0);
    $receipts = $this->socket->await('origin');
    expect($receipts)->toHaveCount(1)
        ->and($receipts[0]['body_hash'])->toBe(hash('sha256', 'sealed-body'))
        ->and($receipts[0]['headers']['host'])->toBe('origin.test:'.$this->socket->ports['tls']);
})->with([false, true]);

it('public send не обходит owner, tenant и исчерпанную quota', function (string $denial): void {
    [$request, $context] = acceptancePair($this);
    if ($denial === 'owner') {
        $revision = (int) $this->table('operations')->where('id', $request->operationId->value)->value('revision');
        $this->operations->cancel($this->tenant, $request->operationId, $revision);
    }
    if ($denial === 'tenant') {
        $this->useTenant(new TenantKey('foreign'));
    }
    if ($denial === 'quota') {
        $revision = (int) $this->table('budgets')->where('id', $this->originBudget->value)->value('revision');
        $this->quota->configure($this->tenant, $this->originBudget, 0, 0, $revision);
    }
    $before = $this->accounting();
    $quota = $this->quotaCounts();
    $result = $this->app->make(OutboundClient::class)->execute($request, $context);
    $expected = match ($denial) {
        'owner' => BlockedReason::OwnerLost,
        'tenant' => BlockedReason::CrossTenantDenied,
        'quota' => DeferralReason::QuotaWindowExhausted,
    };
    expect($result instanceof ExecutionBlocked || $result instanceof ExecutionDeferred)->toBeTrue()
        ->and($result->reason)->toBe($expected)
        ->and($this->accounting())->toBe($before)->and($this->quotaCounts())->toBe($quota)
        ->and($this->table('attempts')->count())->toBe(0)
        ->and($this->table('permits')->whereNotNull('authorized_at')->count())->toBe(0);
    $this->socket->assertNoConnections();
})->with(['owner', 'tenant', 'quota']);

it('public send сохраняет TLS verification без origin bytes и hidden retry', function (string $listener): void {
    [$request, $context] = acceptancePair($this, $listener);
    $result = $this->app->make(OutboundClient::class)->execute($request, $context);
    expect($result)->toBeInstanceOf(ExecutionBlocked::class)
        ->and($this->socket->events('origin'))->toBe([])
        ->and($this->table('attempts')->count())->toBe(1)
        ->and($this->table('permits')->whereNotNull('authorized_at')->count())->toBe(1);
})->with(['wrong', 'expired', 'untrusted']);

it('public send удерживает unknown и consumed quota после transport exception и prune', function (): void {
    [$request, $context] = acceptancePair($this, idempotency: IdempotencyClass::AmbiguousWrite);
    $transport = new class implements PhysicalTransport
    {
        public int $calls = 0;

        public function sendOnce(AuthorizedWireRequest $request): AttemptResult
        {
            $request->consume();
            $this->calls++;
            throw new RuntimeException('fixture transport fault');
        }
    };
    $this->app->instance(PhysicalTransport::class, $transport);
    $result = $this->app->make(OutboundClient::class)->execute($request, $context);
    $quota = $this->quotaCounts();
    expect($result)->toBeInstanceOf(ExecutionUnknownAfterAuthorization::class)
        ->and($transport->calls)->toBe(1)
        ->and($this->table('attempts')->value('status'))->toBe('unknown_after_authorization')
        ->and((int) $this->table('quota_windows')->sum('spent'))->toBe(2);
    $this->app->make(RetentionPruner::class)->prune($this->tenant, limit: 32);
    expect($this->table('attempts')->count())->toBe(1)->and($this->quotaCounts())->toBe($quota)
        ->and($this->table('permits')->whereNotNull('authorized_at')->count())->toBe(1);
    $this->socket->assertNoConnections();
});

it('qualification collector отвергает неполные, stale, duplicate и failed evidence', function (): void {
    $process = new Process(['python3', 'tests/Architecture/test_acceptance_report.py'], dirname(__DIR__, 2));
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getErrorOutput())->toContain('Ran 6 tests', 'OK');
});
