<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Proxyma\Application\Admission\ClaimSend;
use Proxyma\Application\Recovery\CompletionCoordinator;
use Proxyma\Contracts\Execution\LockedCompletionReducer;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Catalog\UpsertRouteCommand;
use Proxyma\Domain\Catalog\UpsertRouteProfileCommand;
use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\CompletionFact;
use Proxyma\Domain\Execution\DecodedTextBody;
use Proxyma\Domain\Execution\LateReceiptClaim;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\ResponseDescriptor;
use Proxyma\Domain\Execution\TransportFailure;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\CircuitState;
use Proxyma\Domain\Health\HealthReducer;
use Proxyma\Domain\Health\HealthScope;
use Proxyma\Domain\Health\HealthSignal;
use Proxyma\Domain\Health\HealthSignals;
use Proxyma\Domain\Health\HealthSnapshot;
use Proxyma\Domain\Health\HealthThresholds;
use Proxyma\Domain\Health\TargetAvailability;
use Proxyma\Tests\Support\CatalogTestCase;
use Proxyma\Tests\Support\ClaimFixtures;
use Proxyma\Tests\Support\DemandFixtures;
use Proxyma\Tests\Support\QuotaFixtures;
use Proxyma\Tests\Support\RecoveryFixtures;
use Proxyma\Tests\Support\ReservationFixtures;

foreach (['CatalogTestCase', 'SelectionFixtures', 'DemandFixtures', 'ReservationFixtures', 'ClaimFixtures', 'QuotaFixtures', 'RecoveryFixtures'] as $file) {
    require_once __DIR__.'/../Support/'.$file.'.php';
}
uses(CatalogTestCase::class, DemandFixtures::class, ReservationFixtures::class, ClaimFixtures::class, QuotaFixtures::class, RecoveryFixtures::class)->group('pg');
beforeEach(fn () => $this->initializeRecoveryFixture());

function healthResponse(int $status): AttemptResult
{
    return AttemptResult::delivered(new ResponseDescriptor($status, ['retry-after' => ['120']], new DecodedTextBody('fixture'), 7, 7));
}

it('сигналы разделяют route, pair и target без parser inference', function (array $summary, array $expected): void {
    $signals = HealthSignals::fromSummary($summary);
    expect([$signals->route->value, $signals->pair->value, $signals->target->value])->toBe($expected);
})->with([
    'proxy connect' => [['failure' => 'proxy_connect', 'endpoint_id' => 'proxy'], ['failure', 'ignored', 'ignored']],
    'proxy auth' => [['failure' => 'proxy_auth', 'endpoint_id' => 'proxy'], ['credential_hold', 'ignored', 'ignored']],
    'timeout' => [['failure' => 'timeout'], ['ignored', 'failure', 'failure']],
    'connect' => [['failure' => 'connect'], ['ignored', 'failure', 'failure']],
    'truncated' => [['failure' => 'truncated'], ['ignored', 'failure', 'failure']],
    '503' => [['delivered' => true, 'status' => 503], ['success', 'success', 'failure']],
    '403' => [['delivered' => true, 'status' => 403], ['success', 'success', 'success']],
    'WAF opt-in' => [['status' => 403, 'applies_waf_hold' => true, 'cooldown_until' => '2030-06-01'], ['ignored', 'maintenance', 'ignored']],
    '429' => [['status' => 429], ['ignored', 'ignored', 'ignored']],
    'storage' => [['failure' => 'storage'], ['ignored', 'ignored', 'ignored']],
    'configuration' => [['failure' => 'configuration'], ['ignored', 'ignored', 'ignored']],
    'tls' => [['failure' => 'tls'], ['ignored', 'ignored', 'ignored']],
    'protocol' => [['failure' => 'protocol'], ['ignored', 'ignored', 'ignored']],
    'byte limit' => [['failure' => 'byte_limit'], ['ignored', 'ignored', 'ignored']],
    'cancelled' => [['failure' => 'cancelled'], ['ignored', 'ignored', 'ignored']],
    'digest only' => [['delivered' => true], ['ignored', 'ignored', 'ignored']],
]);

it('pure reducer применяет thresholds, ratio, corroboration и bounded backoff', function (): void {
    $now = new DateTimeImmutable('2030-06-01T10:10:00Z');
    $row = new HealthSnapshot(Uuid::random(), CircuitState::Closed, 1, 1, 4);
    $r = new HealthReducer;
    $route = $r->reduce(HealthScope::Route, $row, HealthSignal::Failure, 'proxy_connect', $now)->nextSnapshot;
    expect($route->state)->toBe(CircuitState::Open)->and($route->healthEpoch)->toBe(2)
        ->and($route->openUntil)->toEqual($now->modify('+1800 seconds'));
    $row = new HealthSnapshot($row->id, CircuitState::Closed, 1, 1);
    expect($r->reduce(HealthScope::RouteTarget, $row, HealthSignal::Failure, 'timeout', $now, 9, 9)->nextSnapshot->state)->toBe(CircuitState::Closed);
    expect($r->reduce(HealthScope::RouteTarget, $row, HealthSignal::Failure, 'timeout', $now, 10, 6)->nextSnapshot->state)->toBe(CircuitState::Open);
    expect($r->reduce(HealthScope::Target, $row, HealthSignal::Failure, 'timeout', $now, independentGroups: 1)->nextSnapshot->availability)->toBe(TargetAvailability::Suspect);
    expect($r->reduce(HealthScope::Target, $row, HealthSignal::Failure, 'timeout', $now, independentGroups: 2)->nextSnapshot->availability)->toBe(TargetAvailability::Unavailable);
    $row = new HealthSnapshot($row->id, CircuitState::Closed, 1, 1, 4, 30);
    expect($r->reduce(HealthScope::Route, $row, HealthSignal::Failure, 'proxy_connect', $now)->nextSnapshot->openUntil)->toEqual($now->modify('+21600 seconds'));
    expect(fn (): HealthThresholds => new HealthThresholds(routeTargetFailurePercent: 101))->toThrow(InvalidArgumentException::class);
});

it('open hold не закрывается success, probe failure reopens, ignored probe освобождается и stable decay работает', function (): void {
    $r = new HealthReducer;
    $now = new DateTimeImmutable('2030-06-01T10:10:00Z');
    $row = new HealthSnapshot(Uuid::random(), CircuitState::Open, 2, 2, reasonCode: 'credential_hold:1');
    expect($r->reduce(HealthScope::Route, $row, HealthSignal::Success, 'http_200', $now))->toBeNull();
    $probe = new HealthSnapshot($row->id, CircuitState::HalfOpen, 2, 2, probeToken: Uuid::random(), probeUntil: $now->modify('+1 minute'));
    expect($r->reduce(HealthScope::Route, $probe, HealthSignal::Failure, 'proxy_connect', $now)->nextSnapshot->state)->toBe(CircuitState::Open);
    expect($r->reduce(HealthScope::Route, $probe, HealthSignal::Ignored, 'cancelled', $now)->nextSnapshot->state)->toBe(CircuitState::Unknown);
    $stable = new HealthSnapshot($row->id, CircuitState::Closed, 2, 2, recentOpenings: 3, stableSince: $now->modify('-600 seconds'));
    expect($r->reduce(HealthScope::Route, $stable, HealthSignal::Success, 'http_200', $now)->nextSnapshot->recentOpenings)->toBe(2);
});

it('transport timeout сохраняет immutable fact, pair failure и target suspect, duplicate не меняет projection', function (): void {
    [, $input] = $this->authorized();
    $result = AttemptResult::failed(TransportFailure::Timeout);
    $this->completion->record($input->operation->operationId, $input->request->physicalAttemptId, $result);
    $before = $this->table('route_target_health')->first();
    $this->completion->record($input->operation->operationId, $input->request->physicalAttemptId, $result);
    expect($this->table('observations')->count())->toBe(1)->and($this->table('observation_applications')->count())->toBe(3)
        ->and($this->table('route_health')->value('consecutive_failures'))->toBe(0)
        ->and((int) $before->consecutive_failures)->toBe(1)->and($this->table('route_target_health')->first())->toEqual($before)
        ->and($this->table('target_health')->value('availability'))->toBe('suspect');
    $this->rejectedSql(fn () => $this->table('observations')->update(['classification' => 'changed']));
    $this->rejectedSql(fn () => $this->table('observations')->delete());
    $this->rejectedSql(fn () => $this->table('observation_applications')->delete());
});

it('stale epoch ограждает только свою область, поздний факт сохраняется после reaper и retirement', function (): void {
    $prepared = $this->authorized();
    $this->moveSqlClock('2030-06-01T10:12:00Z');
    $this->reconciler->run($this->tenant);
    $spent = $this->quotaCounts();
    $released = $this->table('permits')->value('lease_released_at');
    $this->table('route_health')->update(['state' => 'open', 'health_epoch' => 2, 'reason_code' => 'new_hold']);
    $this->table('targets')->update(['retired_at' => $this->db->raw('clock_timestamp()'), 'revision' => 2]);
    $this->table('operations')->update(['status' => 'cancelled', 'revision' => 2]);
    $receipt = $this->late($prepared);
    $result = $this->completion->recordLateReceipt($receipt);
    expect($result->receiptPersisted)->toBeTrue()->and($result->healthReducerAllowed)->toBeFalse()
        ->and($this->table('route_health')->value('reason_code'))->toBe('new_hold')
        ->and($this->table('route_target_health')->value('last_success_at'))->not->toBeNull()
        ->and($this->table('targets')->value('retired_at'))->not->toBeNull()
        ->and($this->table('permits')->value('state'))->toBe('completed')
        ->and($this->table('permits')->value('lease_released_at'))->toBe($released)->and($this->quotaCounts())->toBe($spent);
    expect($this->completion->recordLateReceipt($receipt)->outcome->value)->toBe('idempotent_duplicate');
    expect(fn () => $this->completion->recordLateReceipt($this->late($prepared, body: 'different')))->toThrow(AdmissionRejected::class, 'receipt_conflict');
});

it('late 429 продлевает только pinned origin при stale epochs и изменении target/egress', function (): void {
    $prepared = $this->authorized();
    $receipt = $this->late($prepared);
    $this->table('target_health')->update(['health_epoch' => 2, 'state' => 'open', 'reason_code' => 'new_hold']);
    $this->table('targets')->update(['canonical_origin' => 'https://new.example.test:443', 'revision' => 2]);
    $this->table('routes')->update(['egress_key' => 'nat:new', 'revision' => 2]);
    $late = new LateReceiptClaim($receipt->operationId, $receipt->physicalAttemptId, $receipt->wireFingerprint, $receipt->receiptId,
        $receipt->receiptDigest, $receipt->authorizationHealthEpochs, healthResponse(429)->response);
    $this->completion->recordLateReceipt($late);
    expect($this->table('budgets')->where('scope_key', $this->scopeKey)->value('cooldown_until'))->toStartWith('2030-06-01 10:12:00')
        ->and($this->table('target_health')->value('reason_code'))->toBe('new_hold');
    $this->completion->recordLateReceipt($late);
    expect($this->table('observations')->count())->toBe(1);
});

it('projection fault откатывает receipt, cooldown, health и release до recoverable authorization', function (): void {
    $prepared = $this->authorized();
    $real = $this->app->make(LockedCompletionReducer::class);
    $this->app->instance(LockedCompletionReducer::class, new class($real) implements LockedCompletionReducer
    {
        public function __construct(private LockedCompletionReducer $real) {}

        public function connection(): Connection
        {
            return $this->real->connection();
        }

        public function apply(CompletionFact $fact, LockedTransactionContext $transaction): void
        {
            $this->real->apply($fact, $transaction);
            throw new RuntimeException('health projection fault');
        }
    });
    [, $input] = $prepared;
    $before = $this->accounting();
    $health = $this->table('route_health')->first();
    expect(fn () => $this->app->make(CompletionCoordinator::class)->record($input->operation->operationId, $input->request->physicalAttemptId, healthResponse(429)))
        ->toThrow(RuntimeException::class, 'health projection fault');
    expect($this->table('attempts')->value('status'))->toBe('authorized')->and($this->table('attempts')->value('receipt_key'))->toBeNull()
        ->and($this->table('observations')->count())->toBe(0)->and($this->table('observation_applications')->count())->toBe(0)
        ->and($this->accounting())->toBe($before)->and($this->table('route_health')->first())->toEqual($health)
        ->and($this->table('budgets')->where('scope_key', $this->scopeKey)->value('cooldown_until'))->toBe('-infinity');
    $this->completion->record($input->operation->operationId, $input->request->physicalAttemptId, healthResponse(429));
    expect($this->table('attempts')->value('status'))->toBe('completed');
});

it('SQL projection применяет configured pair threshold и сохраняет новый open от старого success', function (): void {
    $this->reconfigureProxyma(['health.routeTargetConsecutive' => 1]);
    $this->completion = $this->app->make(CompletionCoordinator::class);
    $old = $this->authorized();
    [, $input] = $this->authorized();
    $this->completion->record($input->operation->operationId, $input->request->physicalAttemptId, AttemptResult::failed(TransportFailure::Timeout));
    $opened = $this->table('route_target_health')->first();
    expect($opened->state)->toBe('open')->and((int) $opened->health_epoch)->toBe(2)
        ->and($opened->open_until)->toStartWith('2030-06-01 10:15:00');
    $this->completion->recordLateReceipt($this->late($old));
    expect($this->table('route_target_health')->first())->toEqual($opened)
        ->and($this->table('route_health')->value('state'))->toBe('closed')
        ->and($this->table('observations')->count())->toBe(2);
});

it('failure fact переживает unknown и late success после reaper без второго charge или release', function (): void {
    $this->reconfigureProxyma(['health.routeTargetMinSample' => 2]);
    $this->reconfigureProxyma(['health.routeTargetFailurePercent' => 50]);
    $this->completion = $this->app->make(CompletionCoordinator::class);
    $prepared = $this->authorized();
    [, $input] = $prepared;
    $this->completion->record($input->operation->operationId, $input->request->physicalAttemptId, AttemptResult::failed(TransportFailure::Timeout));
    $release = $this->table('permits')->value('lease_released_at');
    $this->moveSqlClock('2030-06-01T10:12:00Z');
    $this->reconciler->run($this->tenant);
    $quota = $this->quotaCounts();
    $this->completion->recordLateReceipt($this->late($prepared));
    expect($this->table('observations')->orderBy('classification')->pluck('classification')->all())->toBe(['transport_failure', 'transport_receipt'])
        ->and($this->table('permits')->value('lease_released_at'))->toBe($release)->and($this->quotaCounts())->toBe($quota)
        ->and($this->table('permits')->value('state'))->toBe('completed')
        ->and($this->table('route_target_health')->value('consecutive_failures'))->toBe(0)
        ->and($this->table('route_target_health')->value('state'))->toBe('closed')
        ->and($this->table('target_health')->value('availability'))->toBe('suspect');
});

it('407 удерживает точную версию без timeout, rotation не даёт старому receipt открыть новую версию', function (bool $rotate): void {
    $this->proxyClaimFixture();
    $this->table('endpoints')->update(['credential_ref' => null, 'credential_version' => null, 'revision' => 2]);
    $prepared = $this->authorized();
    [, $input] = $prepared;
    if ($rotate) {
        $this->table('endpoints')->update(['credential_ref' => 'fixture:rotated', 'credential_version' => 2, 'revision' => 3]);
    }
    $this->completion->record($input->operation->operationId, $input->request->physicalAttemptId, healthResponse(407));
    expect($this->table('route_health')->value('state'))->toBe($rotate ? 'closed' : 'open')
        ->and($this->table('route_health')->value('reason_code'))->toBe($rotate ? null : 'credential_hold:1')
        ->and($this->table('route_health')->value('open_until'))->toBeNull()->and($this->table('observations')->count())->toBe(1);
    $this->completion->record($input->operation->operationId, $input->request->physicalAttemptId, healthResponse(407));
    expect($this->table('observations')->count())->toBe(1);
})->with([true, false]);

it('late receipt без probe purpose не закрывает чужой half-open token', function (): void {
    $prepared = $this->authorized();
    $this->table('route_health')->update(['state' => 'half_open', 'probe_token' => Uuid::random()->value,
        'probe_until' => '2030-06-01 10:11:00+00']);
    $before = $this->table('route_health')->first();
    $this->completion->recordLateReceipt($this->late($prepared));
    expect($this->table('route_health')->first())->toEqual($before)
        ->and($this->table('observation_applications')->where('application_key', 'like', 'route_health:%')->value('reducer_allowed'))->toBeFalse();
});

it('explicit narrow cooldown остаётся у pinned egress после его изменения', function (): void {
    $this->reconfigureProxyma(['health_cooldown_scopes' => ['route-origin', 'egress-origin']]);
    $this->app->forgetInstance(ClaimSend::class);
    $this->claims = $this->app->make(ClaimSend::class);
    [, $input] = $this->authorized();
    $snapshot = json_decode($this->table('attempts')->value('snapshot'), true);
    expect($snapshot['cooldown_budget_ids'])->toHaveCount(3);
    $this->table('routes')->update(['egress_key' => 'nat:new', 'revision' => 2]);
    $this->completion->record($input->operation->operationId, $input->request->physicalAttemptId, healthResponse(429));
    expect($this->table('budgets')->whereIn('id', $snapshot['cooldown_budget_ids'])->where('cooldown_until', '>', '2030-06-01 10:10:00+00')->count())->toBe(3);
});

it('SQL ratio использует physical observations текущего epoch и открывает pair на десятом sample', function (): void {
    $this->reconfigureProxyma(['health.routeTargetConsecutive' => 100]);
    $this->completion = $this->app->make(CompletionCoordinator::class);
    $prepared = [];
    for ($i = 0; $i < 10; $i++) {
        $prepared[] = $this->authorized();
    }
    foreach ($prepared as $i => [, $input]) {
        $this->completion->record($input->operation->operationId, $input->request->physicalAttemptId,
            $i < 4 ? $this->delivered() : AttemptResult::failed(TransportFailure::Timeout));
        expect($this->table('route_target_health')->value('state'))->toBe($i === 9 ? 'open' : 'closed');
    }
    expect($this->table('observations')->count())->toBe(10)->and($this->table('target_health')->value('availability'))->toBe('suspect');
});

it('expired reducer window не учитывает старый failure, сохраняя сам факт', function (): void {
    $this->reconfigureProxyma(['health.routeTargetConsecutive' => 100]);
    $this->reconfigureProxyma(['health.routeTargetMinSample' => 2]);
    $this->completion = $this->app->make(CompletionCoordinator::class);
    [, $first] = $this->authorized();
    $this->completion->record($first->operation->operationId, $first->request->physicalAttemptId, AttemptResult::failed(TransportFailure::Timeout));
    $this->moveSqlClock('2030-06-01T10:21:00Z');
    [, $second] = $this->authorized();
    $this->completion->record($second->operation->operationId, $second->request->physicalAttemptId, AttemptResult::failed(TransportFailure::Timeout));
    expect($this->table('route_target_health')->value('state'))->toBe('closed')->and($this->table('observations')->count())->toBe(2);
});

it('две pinned independence groups подтверждают target outage без global alternative-route inference', function (): void {
    $this->table('routes')->update(['independence_group' => 'group:a', 'revision' => 2]);
    $first = $this->authorized();
    $oldRoute = $this->catalog['route'];
    $other = Uuid::random();
    $this->commands()->upsertRoute(new UpsertRouteCommand($other, $this->tenant, 'independent-b',
        RouteKind::Direct, null, true, EgressIdentity::explicit('nat:other'), 'group:b', false, $this->actor()));
    $this->commands()->upsertProfile(new UpsertRouteProfileCommand($this->catalog['profile'], $this->tenant, 'default',
        [$oldRoute, $other], 'uniform_random', $this->actor(1)));
    selectionHealth($this->db, $this->tenant);
    $origin = CanonicalOrigin::of('https', 'example.test');
    $this->budget($origin->routeScopeKey($other));
    $this->budget($origin->egressScopeKey('nat:other'));
    $this->catalog['route'] = $other;
    $second = $this->authorized();
    foreach ([$first, $second] as $i => [, $input]) {
        $this->completion->record($input->operation->operationId, $input->request->physicalAttemptId, AttemptResult::failed(TransportFailure::Timeout));
        expect($this->table('target_health')->value('availability'))->toBe($i === 0 ? 'suspect' : 'unavailable');
    }
    expect($this->table('target_health')->value('state'))->toBe('open')->and($this->table('route_health')->where('state', 'open')->count())->toBe(0);
});
