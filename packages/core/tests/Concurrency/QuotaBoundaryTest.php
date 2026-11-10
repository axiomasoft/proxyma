<?php

declare(strict_types=1);

use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Tests\Support\CatalogTestCase;
use Proxyma\Tests\Support\ClaimFixtures;
use Proxyma\Tests\Support\DemandFixtures;
use Proxyma\Tests\Support\QuotaFixtures;
use Proxyma\Tests\Support\ReservationFixtures;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Support/CatalogTestCase.php';
require_once __DIR__.'/../Support/SelectionFixtures.php';
require_once __DIR__.'/../Support/DemandFixtures.php';
require_once __DIR__.'/../Support/ReservationFixtures.php';
require_once __DIR__.'/../Support/ClaimFixtures.php';
require_once __DIR__.'/../Support/QuotaFixtures.php';

uses(CatalogTestCase::class, DemandFixtures::class, ReservationFixtures::class, ClaimFixtures::class, QuotaFixtures::class);
beforeEach(function (): void {
    $this->initializeQuotaFixture();
    $this->barrier = $this->connectionPdo();
    $this->observer = $this->connectionPdo();
    $this->workerSchema = $this->fixtureSchema;
    $this->quotaWorkers = [];
});
afterEach(function (): void {
    if ($this->barrier->inTransaction()) {
        $this->barrier->rollBack();
    }
    foreach ($this->quotaWorkers as $worker) {
        if ($worker->isRunning()) {
            $worker->stop(0.1);
        }
    }
});

function quotaWorker($test, array $prepared, string $label, string $mode = 'reserve', bool $gate = false): Process
{
    [$ticketOrPermit,$input] = $prepared;
    $worker = (new Process(['php', dirname(__DIR__).'/Support/QuotaWorker.php', json_encode([
        'schema' => $test->workerSchema, 'label' => $label, 'mode' => $mode, 'cas_gate' => $gate,
        'operation' => $input->operation->operationId->value, 'attempt' => $input->request->physicalAttemptId->value,
        'deadline' => $input->request->deadlineAt->format('U.u'), 'route' => $input->selection->routeId->value,
        'permit' => $mode === 'reserve' ? null : $ticketOrPermit->permitId->value, 'class' => $input->request->trafficClass,
        'classes' => $test->workerClasses, 'budget' => $test->originBudget->value, 'hour' => 0, 'day' => 0, 'revision' => 2,
    ], JSON_THROW_ON_ERROR)]))->setTimeout(15);
    $test->quotaWorkers[] = $worker;

    return $worker;
}

function quotaAwait($test, int $count): void
{
    $until = hrtime(true) + 6000000000;
    do {
        $q = $test->observer->prepare("SELECT count(*) FROM pg_stat_activity WHERE application_name LIKE ? AND wait_event_type='Lock'");
        $q->execute(['proxyma-p41-'.$test->workerSchema.'-%']);
        $found = (int) $q->fetchColumn();
        if ($found === $count) {
            break;
        }usleep(10000);
    } while (hrtime(true) < $until);
    expect($found)->toBe($count, implode("\n", array_map(fn ($w) => $w->isStarted() ? $w->getErrorOutput().$w->getOutput() : '', $test->quotaWorkers)));
}

function quotaResult(Process $worker): array
{
    $worker->wait();
    expect($worker->getExitCode())->toBe(0, $worker->getErrorOutput());

    return json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

function quotaRace($test, array $workers): array
{
    $test->barrier->beginTransaction();
    $test->barrier->query('SELECT id FROM pxm_budgets ORDER BY scope_key COLLATE "C",tenant_key COLLATE "C" FOR UPDATE')->fetchAll();
    try {
        foreach ($workers as $index => $worker) {
            $worker->start();
            quotaAwait($test, $index + 1);
        }
    } finally {
        $test->barrier->rollBack();
    }

    return array_map(quotaResult(...), $workers);
}

it('последняя единица hour/day в двух scopes выдаётся одному независимому worker', function (): void {
    $this->quota->configure($this->tenant, $this->originBudget, 1, 1, 1);
    $other = new Uuid($this->table('budgets')->where('id', '<>', $this->originBudget->value)->value('id'));
    $this->quota->configure($this->tenant, $other, 1, 1, 1);
    $one = $this->quotaEnqueued();
    $two = $this->quotaEnqueued();
    $results = quotaRace($this, [quotaWorker($this, $one, 'one'), quotaWorker($this, $two, 'two')]);
    $states = array_column($results, 'status');
    sort($states);
    expect($states)->toBe(['reserved', 'wait'])->and(count(array_unique(array_column($results, 'pid'))))->toBe(2)
        ->and($this->table('quota_windows')->where('reserved', 1)->count())->toBe(4)
        ->and($this->table('quota_reservations')->count())->toBe(4)->and($this->table('permits')->count())->toBe(1)
        ->and($this->table('budgets')->where('inflight', 1)->count())->toBe(3);
});

it('параллельные named classes не обходят общий origin quota', function (): void {
    $this->trafficShares(['background' => 30, 'interactive' => 70]);
    $this->quota->configure($this->tenant, $this->originBudget, 1, 1, 1);
    $origin = CanonicalOrigin::of('https', 'example.test');
    foreach (['background', 'interactive'] as $class) {
        $this->quota->configureTrafficShare($this->tenant, $this->originBudget, $origin, $class, 2);
    }
    $one = $this->quotaEnqueued('background');
    $two = $this->quotaEnqueued('interactive');
    $results = quotaRace($this, [quotaWorker($this, $one, 'one'), quotaWorker($this, $two, 'two')]);
    $states = array_column($results, 'status');
    sort($states);
    $children = $this->table('budgets')->whereNotNull('traffic_class')->pluck('id')->all();
    expect($states)->toBe(['reserved', 'wait'])->and((int) $this->table('quota_windows')->whereIn('budget_id', $children)->sum('reserved'))->toBe(1)
        ->and($this->table('quota_reservations')->count())->toBe(3)->and($this->table('permits')->count())->toBe(1);
});

it('duplicate claim тратит quota и выдаёт fake bytes ровно один раз', function (): void {
    $this->quota->configure($this->tenant, $this->originBudget, 1, 1, 1);
    $prepared = $this->quotaPrepared();
    $results = quotaRace($this, [quotaWorker($this, $prepared, 'one', 'claim'), quotaWorker($this, $prepared, 'two', 'claim')]);
    $states = array_column($results, 'status');
    sort($states);
    expect($states)->toBe(['already_claimed', 'sent'])->and(array_sum(array_column($results, 'bytes')))->toBe(1)
        ->and((int) $this->table('quota_windows')->sum('reserved'))->toBe(0)->and((int) $this->table('quota_windows')->sum('spent'))->toBe(2)
        ->and($this->table('attempts')->count())->toBe(1);
});

it('rollover за locked reads не авторизует старое окно и освобождает reservation после rollback', function (): void {
    $this->sqlClock('2030-12-31T23:59:59Z');
    $this->quota->configure($this->tenant, $this->originBudget, 1, 1, 1);
    $prepared = $this->quotaPrepared();
    $before = $this->table('budgets')->orderBy('id')->pluck('tat')->all();
    $this->barrier->beginTransaction();
    $this->barrier->query("SELECT pg_advisory_xact_lock(hashtextextended('fixture:quota-cas-gate',0))")->fetchAll();
    $worker = quotaWorker($this, $prepared, 'one', 'claim', true);
    try {
        $worker->start();
        quotaAwait($this, 1);
        $this->moveSqlClock('2031-01-01T00:00:01Z');
    } finally {
        $this->barrier->commit();
    }
    $result = quotaResult($worker);
    expect($result['status'])->toBe('replan')->and($result['released'])->toBeTrue()->and($result['bytes'])->toBe(0)
        ->and((int) $this->table('quota_windows')->sum('reserved'))->toBe(0)->and((int) $this->table('quota_windows')->sum('spent'))->toBe(0)
        ->and((int) $this->table('budgets')->sum('inflight'))->toBe(0)->and($this->table('budgets')->orderBy('id')->pluck('tat')->all())->toBe($before)
        ->and($this->table('attempts')->count())->toBe(0)->and($this->table('permits')->value('state'))->toBe('expired_before_send');
    $one = $this->quotaEnqueued();
    $two = $this->quotaEnqueued();
    $results = quotaRace($this, [quotaWorker($this, $one, 'one'), quotaWorker($this, $two, 'two')]);
    $states = array_column($results, 'status');
    sort($states);
    expect($states)->toBe(['reserved', 'wait'])->and((int) $this->table('quota_windows')->sum('reserved'))->toBe(2)
        ->and($this->table('quota_reservations')->where('state', 'released')->count())->toBe(2);
});

it('limit writer перед ожидающим claim сохраняет reserved usage и запрещает overspend', function (): void {
    $this->quota->configure($this->tenant, $this->originBudget, 2, 2, 1);
    $prepared = $this->quotaPrepared();
    $before = $this->accounting();
    $ids = $this->table('quota_windows')->orderBy('id')->pluck('id')->all();
    $results = quotaRace($this, [quotaWorker($this, $prepared, 'one', 'configure'), quotaWorker($this, $prepared, 'two', 'claim')]);
    expect($results[0]['status'])->toBe('configured')->and($results[1]['status'])->toBe('claim_evidence_changed')
        ->and(array_sum(array_column($results, 'bytes')))->toBe(0)->and($this->accounting())->toBe($before)
        ->and($this->table('quota_windows')->orderBy('id')->pluck('id')->all())->toBe($ids)
        ->and((int) $this->table('quota_windows')->sum('reserved'))->toBe(2)->and((int) $this->table('quota_windows')->sum('spent'))->toBe(0);
});
