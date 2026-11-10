<?php

declare(strict_types=1);

use Proxyma\Application\Execution\DestinationPolicy;
use Proxyma\Application\Execution\OutboundExecutor;
use Proxyma\Contracts\DestinationResolver;
use Proxyma\Contracts\PhysicalTransport;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\AuthorizedWireRequest;
use Proxyma\Domain\Execution\DemandPurpose;
use Proxyma\Domain\Execution\LateReceiptClaim;
use Proxyma\Domain\Execution\Duration;
use Proxyma\Domain\Execution\ExecutionContext;
use Proxyma\Domain\Execution\ExecutionSucceeded;
use Proxyma\Domain\Execution\OutboundRequest;
use Proxyma\Domain\Execution\TransportFailure;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\ProbeClaim;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Infrastructure\Http\DnsResolver;
use Proxyma\Tests\Support\CatalogTestCase;
use Proxyma\Tests\Support\ClaimFixtures;
use Proxyma\Tests\Support\DemandFixtures;
use Proxyma\Tests\Support\HealthFixtures;
use Proxyma\Tests\Support\QuotaFixtures;
use Proxyma\Tests\Support\RecoveryFixtures;
use Proxyma\Tests\Support\ReservationFixtures;
use Symfony\Component\Process\Process;

foreach (['CatalogTestCase', 'SelectionFixtures', 'DemandFixtures', 'ReservationFixtures', 'ClaimFixtures', 'QuotaFixtures', 'RecoveryFixtures', 'HealthFixtures'] as $file) {
    require_once __DIR__.'/../Support/'.$file.'.php';
}
uses(CatalogTestCase::class, DemandFixtures::class, ReservationFixtures::class, ClaimFixtures::class, QuotaFixtures::class, RecoveryFixtures::class, HealthFixtures::class);
beforeEach(function (): void {
    $this->initializeHealthFixture();
    $this->probeWorkers = [];
    $this->probeSchema = $this->fixtureSchema;
    $this->probeBarrier = $this->connectionPdo();
    $this->probeObserver = $this->connectionPdo();
});
afterEach(function (): void {
    if ($this->probeBarrier->inTransaction()) {
        $this->probeBarrier->rollBack();
    }
    foreach ($this->probeWorkers as $worker) {
        if ($worker->isRunning()) {
            $worker->stop(0.1, 9);
        }
    }
    $this->probeBarrier->query('SELECT pg_advisory_unlock_all()');
});

function probeContender($test, array $intent, string $label): Process
{
    $worker = new Process(['php', dirname(__DIR__).'/Support/QuotaWorker.php', json_encode([
        'schema' => $test->probeSchema, 'label' => $label, 'mode' => 'probe_claim', 'classes' => [], 'cas_gate' => false,
        'operation' => $intent['operation']->operationId->value, 'attempt' => $intent['request']->physicalAttemptId->value,
        'route' => $intent['route']->routeId->value, 'deadline' => $intent['operation']->deadlineAt->format('U.u'), 'class' => 'default',
    ], JSON_THROW_ON_ERROR)]);
    $worker->setTimeout(15);
    $test->probeWorkers[] = $worker;
    $worker->start();

    return $worker;
}

function probeAwaitLock($test, Process $worker, string $label): void
{
    $until = hrtime(true) + 6000000000;
    do {
        $first = explode("\n", trim($worker->getOutput()))[0];
        if ($first !== '') {
            $ready = json_decode($first, true, flags: JSON_THROW_ON_ERROR);
            $query = $test->probeObserver->prepare("SELECT query FROM pg_stat_activity WHERE pid=? AND wait_event_type='Lock'");
            $query->execute([$ready['pid']]);
            $sql = $query->fetchColumn();
            if (is_string($sql) && str_contains($sql, 'pg_advisory_lock_shared')) {
                return;
            }
        }
        usleep(10000);
    } while (hrtime(true) < $until && $worker->isRunning());
    throw new RuntimeException('Probe contender '.$label.' did not reach the start barrier: '.$worker->getOutput().$worker->getErrorOutput());
}

it('два независимых PostgreSQL процесса получают ровно одного владельца всего compound set', function (): void {
    $this->healthUnknown();
    $one = $this->quotaIntent(purpose: DemandPurpose::Probe);
    $two = $this->quotaIntent(purpose: DemandPurpose::Probe);
    $gate = $this->probeBarrier->prepare('SELECT pg_advisory_lock(hashtextextended(?,0))');
    $gate->execute(['fixture:probe-start:'.$this->probeSchema]);
    $a = probeContender($this, $one, 'one');
    probeAwaitLock($this, $a, 'one');
    $b = probeContender($this, $two, 'two');
    probeAwaitLock($this, $b, 'two');
    $gate = $this->probeBarrier->prepare('SELECT pg_advisory_unlock(hashtextextended(?,0))');
    $gate->execute(['fixture:probe-start:'.$this->probeSchema]);
    $results = [];
    foreach ([$a, $b] as $worker) {
        $worker->wait();
        expect($worker->getExitCode())->toBe(0, $worker->getErrorOutput());
        $lines = explode("\n", trim($worker->getOutput()));
        $results[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }
    expect(array_unique(array_column($results, 'pid')))->toHaveCount(2);
    $statuses = array_column($results, 'status');
    sort($statuses);
    expect($statuses)->toBe(['claimed', 'probe_already_owned'])->and($this->table('probe_claims')->count())->toBe(1)
        ->and($this->table('attempts')->count())->toBe(0);
    $winner = $results[0]['status'] === 'claimed' ? $one : $two;
    $token = $winner['operation']->probeToken->value;
    foreach (['route_health', 'route_target_health', 'target_health'] as $scope) {
        expect($this->table($scope)->value('state'))->toBe('half_open')->and($this->table($scope)->value('probe_token'))->toBe($token);
    }
    $prepared = $this->sendProbe($winner);
    [, $input] = $prepared;
    expect($this->physical->sends)->toBe(1)->and($this->table('quota_reservations')->where('state', 'consumed')->count())->toBe(4);
    $this->completion->record($input->operation->operationId, $input->request->physicalAttemptId, $this->delivered());
    foreach (['route_health', 'route_target_health', 'target_health'] as $scope) {
        expect($this->table($scope)->value('state'))->toBe('closed')->and($this->table($scope)->value('probe_token'))->toBeNull();
    }
});

it('claim требует непустые typed epochs, конечный lease и purpose, отказ второго scope атомарен', function (): void {
    expect(fn () => new ProbeClaim(Uuid::random(), [], new DateTimeImmutable('+1 minute')))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ProbeClaim(Uuid::random(), ['foreign:'.Uuid::random()->value => 1], new DateTimeImmutable('+1 minute')))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ProbeClaim(Uuid::random(), ['route_health:'.Uuid::random()->value => 0], new DateTimeImmutable('+1 minute')))->toThrow(InvalidArgumentException::class);
    $intent = $this->quotaIntent(purpose: DemandPurpose::Probe);
    $input = $this->reservationInput($intent);
    expect(fn () => $this->probes->claim($input))->toThrow(AdmissionRejected::class, 'probe_scope_empty');
    expect(fn () => $this->probes->claim($input, 301))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->probes->claim($this->reservationInput($this->quotaIntent())))->toThrow(InvalidArgumentException::class);
    $this->healthUnknown();
    $this->table('route_target_health')->update(['state' => 'open', 'open_until' => '2030-06-01 10:20:00+00']);
    $before = $this->table('route_health')->first();
    expect(fn () => $this->probes->claim($input))->toThrow(AdmissionRejected::class, 'probe_not_due');
    expect($this->table('route_health')->first())->toEqual($before)->and($this->table('probe_claims')->count())->toBe(0);
    $this->rejectedSql(fn () => $this->table('probe_claims')->insert(['id' => Uuid::random()->value, 'tenant_key' => $this->tenant->value,
        'operation_id' => $intent['operation']->operationId->value, 'route_id' => $this->catalog['route']->value, 'target_id' => $this->catalog['target']->value,
        'epochs' => '{}', 'created_at' => '2030-06-01 10:10:00+00', 'expires_at' => 'infinity']));
});

it('admission отвергает token без claim, extra или missing scope и stale epoch без charge', function (string $tamper): void {
    $this->healthUnknown();
    [$intent, $claim] = $this->healthProbe();
    if ($tamper === 'epoch') {
        $this->table('route_health')->increment('health_epoch');
    } elseif ($tamper === 'missing') {
        $this->table('route_health')->update(['state' => 'closed', 'probe_token' => null, 'probe_until' => null]);
    } elseif ($tamper === 'token') {
        $this->table('route_health')->update(['probe_token' => Uuid::random()->value]);
    } elseif ($tamper === 'claim') {
        $this->table('probe_claims')->delete();
    } else {
        $epochs = $claim->epochs->epochs;
        array_pop($epochs);
        $this->table('probe_claims')->update(['epochs' => json_encode($epochs, JSON_THROW_ON_ERROR)]);
    }
    $before = $this->accounting();
    $quotas = $this->quotaCounts();
    expect(fn () => $this->quotaPrepared(intent: $intent))->toThrow(AdmissionRejected::class);
    expect($this->accounting())->toBe($before)->and($this->quotaCounts())->toBe($quotas)->and($this->table('attempts')->count())->toBe(0);
})->with(['epoch', 'missing', 'token', 'claim', 'extra']);

it('точный subset закрывает только claimed circuit и расходует общие плюс probe quotas ровно один раз', function (): void {
    $this->healthUnknown(['route_target_health']);
    [$intent, $claim] = $this->healthProbe();
    expect($claim->epochs->epochs)->toHaveCount(1);
    $ordinary = $this->quotaIntent();
    expect(fn () => $this->quotaPrepared(intent: $ordinary))->toThrow(AdmissionRejected::class, 'health_unavailable');
    $demand = new Uuid($this->table('demands')->where('operation_id', $ordinary['operation']->operationId->value)->value('id'));
    $this->queue->cancel($this->tenant, $ordinary['operation']->operationId, $demand);
    $prepared = $this->sendProbe($intent);
    [, $input] = $prepared;
    $route = $this->table('route_health')->first();
    $target = $this->table('target_health')->first();
    $this->completion->record($input->operation->operationId, $input->request->physicalAttemptId, $this->delivered());
    expect($this->table('route_health')->first())->toEqual($route)->and($this->table('target_health')->first())->toEqual($target)
        ->and($this->table('route_target_health')->value('state'))->toBe('closed')
        ->and($this->physical->sends)->toBe(1)->and((int) $this->table('quota_windows')->sum('spent'))->toBe(4);
    expect(fn () => $this->probes->claim($this->reservationInput($intent)))->toThrow(AdmissionRejected::class, 'probe_token_reused');
    expect(fn () => $this->quotaPrepared(intent: $intent))->toThrow(AdmissionRejected::class, 'probe_ownership_lost');
});

it('expired lease reopens с bounded backoff, delayed old completion не закрывает нового владельца', function (int $status): void {
    $this->healthUnknown();
    [$oldIntent, $oldClaim] = $this->healthProbe(5);
    $old = $this->sendProbe($oldIntent);
    $this->moveSqlClock('2030-06-01T10:10:06Z');
    $next = $this->quotaIntent(purpose: DemandPurpose::Probe);
    expect(fn () => $this->probes->claim($this->reservationInput($next)))->toThrow(AdmissionRejected::class, 'probe_expired_backoff');
    foreach (['route_health', 'route_target_health', 'target_health'] as $scope) {
        expect($this->table($scope)->value('state'))->toBe('open')->and($this->table($scope)->value('open_until'))->toStartWith('2030-06-01 10:10:36');
    }
    $this->moveSqlClock('2030-06-01T10:10:37Z');
    $this->reconciler->run($this->tenant);
    [$newIntent, $newClaim] = $this->healthProbe(20);
    expect($newClaim->token->value)->not->toBe($oldClaim->token->value);
    $new = $this->sendProbe($newIntent);
    $rows = [];
    foreach (['route_health', 'route_target_health', 'target_health'] as $scope) {
        $rows[$scope] = $this->table($scope)->first();
    }
    $receipt = $this->late($old);
    $late = new LateReceiptClaim($receipt->operationId, $receipt->physicalAttemptId, $receipt->wireFingerprint,
        $receipt->receiptId, $receipt->receiptDigest, $receipt->authorizationHealthEpochs, $this->healthResponse($status)->response);
    expect($this->completion->recordLateReceipt($late)->healthReducerAllowed)->toBeFalse();
    foreach ($rows as $scope => $row) {
        expect($this->table($scope)->first())->toEqual($row);
    }
    [, $input] = $new;
    $this->completion->record($input->operation->operationId, $input->request->physicalAttemptId, $this->delivered());
    expect($this->table('route_target_health')->value('state'))->toBe('closed')
        ->and($this->table('observations')->count())->toBe(2)->and($this->physical->sends)->toBe(2);
})->with([200, 429]);

it('probe failure reopens и ordinary stale success не восстанавливает новый circuit', function (): void {
    $ordinary = $this->authorized();
    $this->healthUnknown(['route_target_health']);
    [$intent] = $this->healthProbe();
    [, $input] = $this->sendProbe($intent);
    $this->completion->record($input->operation->operationId, $input->request->physicalAttemptId, AttemptResult::failed(TransportFailure::Timeout));
    $open = $this->table('route_target_health')->first();
    expect($open->state)->toBe('open')->and($open->open_until)->not->toBeNull();
    $this->completion->recordLateReceipt($this->late($ordinary));
    expect($this->table('route_target_health')->first())->toEqual($open);
});

it('completion не восстанавливает даже первый scope при потере части compound claim', function (): void {
    $this->healthUnknown();
    [$intent] = $this->healthProbe();
    $prepared = $this->sendProbe($intent);
    $this->table('target_health')->increment('health_epoch');
    $row = $this->table('route_health')->first();
    $this->completion->recordLateReceipt($this->late($prepared));
    expect($this->table('route_health')->first())->toEqual($row)
        ->and($this->table('observation_applications')->where('reducer_allowed', true)->count())->toBe(0);
});

it('probe проходит общий OutboundExecutor без обычного healthy selector и без повторного transport bypass', function (int $status): void {
    $this->app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    $this->app['config']->set('proxyma.policy.project_defaults', ['route.allow_direct' => true]);
    $this->healthUnknown();
    [$intent] = $this->healthProbe();
    $scope = $this->transactions->scope;
    $dns = new DnsResolver($scope, static fn () => ['93.184.216.34']);
    $this->app->instance(DestinationResolver::class, new DestinationPolicy($dns));
    $transport = new class($this->healthResponse($status)) implements PhysicalTransport
    {
        public int $sends = 0;

        public function __construct(private AttemptResult $result) {}

        public function sendOnce(AuthorizedWireRequest $request): AttemptResult
        {
            $request->consume();
            $this->sends++;

            return $this->result;
        }
    };
    $this->app->instance(PhysicalTransport::class, $transport);
    $subjects = new SubjectChain(new SubjectRef($this->tenant, 'fixture', 'probe'));
    $context = new ExecutionContext($intent['operation'], $subjects,
        new Duration(10000), hrtime(true) + 10000000000);
    $request = new OutboundRequest($intent['operation']->operationId, $this->catalog['target'],
        'GET', $intent['request']->url, $intent['request']->timeouts);
    $result = $this->app->make(OutboundExecutor::class)->execute($request, $context);
    expect($transport->sends)->toBe(1)->and($this->table('attempts')->count())->toBe(1)
        ->and((int) $this->table('quota_windows')->sum('spent'))->toBe(4);
    if ($status === 200) {
        expect($result)->toBeInstanceOf(ExecutionSucceeded::class);
    }
})->with([200, 503]);

it('финальный send CAS проверяет probe lease свежим DB clock и откатывает consume при expiry', function (): void {
    $this->healthUnknown();
    [$intent] = $this->healthProbe(5);
    [$permit, $input, $wire] = $this->quotaPrepared(intent: $intent);
    $before = $this->quotaCounts();
    $gated = false;
    $db = $this->db;
    $db->beforeExecuting(function (string $sql) use ($db, &$gated): void {
        if (! $gated && str_contains($sql, 'WITH instant AS MATERIALIZED')) {
            $gated = true;
            $db->table('fixture_clock')->update(['instant' => '2030-06-01 10:10:06+00']);
        }
    });
    expect(fn () => $this->claims->claim($permit, $input, $wire))->toThrow(AdmissionRejected::class, 'final_send_cas_denied');
    expect($gated)->toBeTrue()->and($this->quotaCounts())->toBe($before)
        ->and($this->table('probe_claims')->value('physical_attempt_id'))->toBeNull()
        ->and($this->table('attempts')->count())->toBe(0);
});
