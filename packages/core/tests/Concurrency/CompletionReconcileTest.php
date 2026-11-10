<?php

declare(strict_types=1);

use Proxyma\Domain\Execution\IdempotencyClass;
use Proxyma\Tests\Support\CatalogTestCase;
use Proxyma\Tests\Support\ClaimFixtures;
use Proxyma\Tests\Support\DemandFixtures;
use Proxyma\Tests\Support\QuotaFixtures;
use Proxyma\Tests\Support\RecoveryFixtures;
use Proxyma\Tests\Support\ReservationFixtures;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

foreach (['CatalogTestCase', 'SelectionFixtures', 'DemandFixtures', 'ReservationFixtures', 'ClaimFixtures', 'QuotaFixtures', 'RecoveryFixtures'] as $file) {
    require_once __DIR__.'/../Support/'.$file.'.php';
}
uses(CatalogTestCase::class, DemandFixtures::class, ReservationFixtures::class, ClaimFixtures::class, QuotaFixtures::class, RecoveryFixtures::class);
beforeEach(function (): void {
    $this->initializeRecoveryFixture();
    $this->barrier = $this->connectionPdo();
    $this->observer = $this->connectionPdo();
    $this->workerSchema = $this->fixtureSchema;
    $this->recoveryWorkers = [];
    $this->db->statement('CREATE TABLE fixture_receipts (physical_attempt_id uuid PRIMARY KEY,bytes integer NOT NULL CHECK(bytes=1))');
});
afterEach(function (): void {
    if ($this->barrier->inTransaction()) {
        $this->barrier->rollBack();
    }
    foreach ($this->recoveryWorkers as $worker) {
        if ($worker->isRunning()) {
            $worker->stop(0.1, 9);
        }
    }
    $this->barrier->query('SELECT pg_advisory_unlock_all()');
});

function recoveryWorker($test, array $prepared, string $label, string $action, ?string $checkpoint = null): Process
{
    [, $input] = $prepared;
    $worker = (new Process(['php', dirname(__DIR__).'/Support/QuotaWorker.php', json_encode([
        'schema' => $test->workerSchema, 'label' => $label, 'mode' => 'reserve', 'cas_gate' => false, 'recovery_action' => $action, 'checkpoint' => $checkpoint,
        'operation' => $input->operation->operationId->value, 'attempt' => $input->request->physicalAttemptId->value,
        'deadline' => $input->request->deadlineAt->format('U.u'), 'route' => $input->selection->routeId->value, 'class' => $input->request->trafficClass,
        'classes' => $test->workerClasses, 'method' => $input->request->method, 'idempotency' => $input->request->idempotency->value,
    ], JSON_THROW_ON_ERROR)]))->setTimeout(15);
    $test->recoveryWorkers[] = $worker;

    return $worker;
}
function recoveryAwait($test, Process $worker, string $label): void
{
    $until = hrtime(true) + 6000000000;
    do {
        $q = $test->observer->prepare("SELECT count(*) FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock'");
        $q->execute(['proxyma-p42-'.$test->workerSchema.'-'.$label]);
        $found = (int) $q->fetchColumn();
        if ($found === 1) {
            break;
        }
        usleep(10000);
    } while (hrtime(true) < $until && $worker->isRunning());
    expect($found)->toBe(1, $worker->getOutput().$worker->getErrorOutput());
}
function recoveryResult(Process $worker): array
{
    $worker->wait();
    expect($worker->getExitCode())->toBe(0, $worker->getErrorOutput());

    return json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('SIGKILL checkpoint восстанавливается без повторной physical attempt', function (string $checkpoint, bool $authorized, bool $bytes): void {
    $intent = $this->quotaIntent();
    $intent['request'] = $this->modifiedWire($intent['request'], ['method' => 'POST', 'idempotency' => IdempotencyClass::AmbiguousWrite]);
    $prepared = $this->quotaEnqueued(intent: $intent);
    $this->barrier->prepare('SELECT pg_advisory_lock(hashtextextended(?,0))')->execute(['fixture:recovery:one']);
    $worker = recoveryWorker($this, $prepared, 'one', 'crash', $checkpoint);
    $worker->start();
    recoveryAwait($this, $worker, 'one');
    expect(json_decode(trim($worker->getOutput()), true, flags: JSON_THROW_ON_ERROR)['ready'])->toBe($checkpoint);
    $worker->signal(9);
    try {
        $worker->wait();
    } catch (ProcessSignaledException $signal) {
        expect($signal->getSignal())->toBe(9);
    }
    expect($worker->hasBeenSignaled())->toBeTrue()->and($worker->getTermSignal())->toBe(9);
    $debt = $this->accounting();
    $this->moveSqlClock('2030-06-01T10:12:00Z');
    $this->reconciler->run($this->tenant);
    $snapshot = $this->quotaCounts();
    expect($this->reconciler->run($this->tenant)->changed)->toBe(0)->and($this->quotaCounts())->toBe($snapshot)
        ->and((int) $this->table('budgets')->sum('inflight'))->toBe(0)->and((int) $this->db->table('fixture_receipts')->sum('bytes'))->toBe($bytes ? 1 : 0)
        ->and((int) $this->table('quota_windows')->sum('spent'))->toBe($authorized ? 2 : 0)
        ->and((int) $this->table('quota_windows')->sum('reserved'))->toBe(0)->and($this->table('attempts')->count())->toBe($authorized ? 1 : 0);
    if ($checkpoint !== 'before_reserve') {
        expect($this->table('permits')->value('state'))->toBe($authorized ? 'unknown_after_authorization' : 'expired_before_send');
        foreach ($this->accounting() as $i => $row) {
            expect($row['tat'])->toBe($debt[$i]['tat'])->and($row['send_tat'])->toBe($debt[$i]['send_tat']);
        }
    }
    if ($bytes) {
        expect($this->completion->recordLateReceipt($this->late($prepared))->receiptPersisted)->toBeTrue()
            ->and((int) $this->table('quota_windows')->sum('spent'))->toBe(2)->and((int) $this->table('budgets')->sum('inflight'))->toBe(0);
    }
})->with([
    'before reserve' => ['before_reserve', false, false],
    'after reserve' => ['after_reserve', false, false],
    'after authorization' => ['after_authorization', true, false],
    'after fixture bytes' => ['after_fixture_bytes', true, true],
    'before receipt' => ['before_receipt', true, true],
]);

it('completion и expiry сериализуются через operation root и сохраняют receipt/release один раз', function (bool $completionFirst): void {
    $prepared = $this->authorized();
    $this->moveSqlClock('2030-06-01T10:12:00Z');
    $this->barrier->beginTransaction();
    $this->barrier->query('SELECT id FROM pxm_budgets ORDER BY scope_key COLLATE "C",tenant_key COLLATE "C" FOR UPDATE')->fetchAll();
    $first = recoveryWorker($this, $prepared, 'one', $completionFirst ? 'complete' : 'reconcile');
    $second = recoveryWorker($this, $prepared, 'two', $completionFirst ? 'reconcile' : 'complete');
    $first->start();
    recoveryAwait($this, $first, 'one');
    $second->start();
    if ($completionFirst) {
        $skip = recoveryResult($second);
        expect($skip['page']['scanned'])->toBe(1)->and($skip['page']['locked'])->toBe(0);
    } else {
        recoveryAwait($this, $second, 'two');
    }
    $this->barrier->rollBack();
    recoveryResult($first);
    if (! $completionFirst) {
        recoveryResult($second);
    }
    $this->reconciler->run($this->tenant);
    expect($this->table('permits')->value('state'))->toBe('completed')->and($this->table('attempts')->whereNotNull('receipt_digest')->count())->toBe(1)
        ->and($this->table('observations')->count())->toBe(1)->and((int) $this->table('budgets')->sum('inflight'))->toBe(0)
        ->and((int) $this->table('quota_windows')->sum('spent'))->toBe(2)->and($this->reconciler->run($this->tenant)->changed)->toBe(0);
})->with([true, false]);

it('duplicate completion двух workers сохраняет один receipt и observation', function (): void {
    $prepared = $this->authorized();
    $this->barrier->beginTransaction();
    $this->barrier->query('SELECT id FROM pxm_budgets ORDER BY scope_key COLLATE "C",tenant_key COLLATE "C" FOR UPDATE')->fetchAll();
    $one = recoveryWorker($this, $prepared, 'one', 'complete');
    $two = recoveryWorker($this, $prepared, 'two', 'complete');
    $one->start();
    recoveryAwait($this, $one, 'one');
    $two->start();
    recoveryAwait($this, $two, 'two');
    $this->barrier->rollBack();
    $results = [recoveryResult($one)['status'], recoveryResult($two)['status']];
    sort($results);
    expect($results)->toBe(['idempotent_duplicate', 'transitioned_to_completed'])->and($this->table('observations')->count())->toBe(1)
        ->and((int) $this->table('budgets')->sum('inflight'))->toBe(0)->and((int) $this->table('quota_windows')->sum('spent'))->toBe(2);
});

it('durable sweep lease исключает параллельный sweep, занятый root пропускается без permit-first lock', function (): void {
    $prepared = $this->authorized();
    $this->moveSqlClock('2030-06-01T10:12:00Z');
    $this->barrier->beginTransaction();
    $this->barrier->query('SELECT id FROM pxm_budgets ORDER BY scope_key COLLATE "C",tenant_key COLLATE "C" FOR UPDATE')->fetchAll();
    $one = recoveryWorker($this, $prepared, 'one', 'reconcile');
    $two = recoveryWorker($this, $prepared, 'two', 'reconcile');
    $one->start();
    recoveryAwait($this, $one, 'one');
    $two->start();
    expect(recoveryResult($two)['page']['busy'])->toBeTrue();
    $this->barrier->rollBack();
    recoveryResult($one);
    expect((int) $this->table('budgets')->sum('inflight'))->toBe(0)->and($this->table('attempts')->value('status'))->toBe('unknown_after_authorization');
});
