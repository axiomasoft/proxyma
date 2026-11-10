<?php

declare(strict_types=1);

use Proxyma\Domain\Admission\QueueLimits;
use Proxyma\Domain\Execution\DemandState;
use Proxyma\Domain\Execution\DemandTicket;
use Proxyma\Tests\Support\CatalogTestCase;
use Proxyma\Tests\Support\DemandFixtures;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Support/CatalogTestCase.php';
require_once __DIR__.'/../Support/DemandFixtures.php';

uses(CatalogTestCase::class, DemandFixtures::class);

beforeEach(function (): void {
    $this->initializeDemandFixture();
});

function demandWorker($test, array $intent, string $mode, string $label, int $capacity = 64, string $demandId = '-'): Process
{
    $process = new Process(['php', dirname(__DIR__).'/Support/ConcurrentDemandWorker.php', $test->fixtureSchemaForDemandWorker,
        $mode, $intent['operation']->operationId->value, $intent['request']->physicalAttemptId->value,
        $intent['request']->deadlineAt->format('U.u'), $label, (string) $capacity, $demandId]);
    $process->setTimeout(15);

    return $process;
}

function releaseDemandWorkers($test, array $workers, ?Closure $beforeRelease = null): array
{
    $monitor = $test->demandMonitor;
    try {
        foreach ($workers as $worker) {
            $worker->start();
        }
        $deadline = hrtime(true) + 6_000_000_000;
        do {
            $q = $test->demandObserver->prepare("SELECT count(*) FROM pg_stat_activity WHERE application_name LIKE ? AND wait_event_type = 'Lock'");
            $q->execute(['proxyma-p32-'.$test->fixtureSchemaForDemandWorker.'-%']);
            $waiting = (int) $q->fetchColumn();
            if ($waiting === count($workers)) {
                break;
            }
            usleep(10000);
        } while (hrtime(true) < $deadline);
        expect($waiting)->toBe(count($workers), implode("\n", array_map(fn ($worker) => $worker->getErrorOutput(), $workers)));
        if ($beforeRelease !== null) {
            $beforeRelease();
        }
    } catch (Throwable $exception) {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(0.1);
            }
        }
        throw $exception;
    } finally {
        $monitor->rollBack();
    }
    $results = [];
    try {
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->getExitCode())->toBe(0, $worker->getErrorOutput());
            $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }
    } finally {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(0.1);
            }
        }
    }

    return $results;
}

beforeEach(function (): void {
    $this->fixtureSchemaForDemandWorker = $this->fixtureSchema;
    $this->demandMonitor = $this->connectionPdo();
    $this->demandObserver = $this->connectionPdo();
});

it('два независимых workers не превышают bounded queue на общем budget', function (): void {
    $one = $this->demandOperation();
    $two = $this->demandOperation();
    $this->demandMonitor->beginTransaction();
    $this->demandMonitor->query('SELECT id FROM pxm_budgets FOR UPDATE')->fetchAll();
    $outcomes = releaseDemandWorkers($this, [demandWorker($this, $one, 'enqueue', 'one', 1), demandWorker($this, $two, 'enqueue', 'two', 1)]);
    $states = array_column($outcomes, 'status');
    sort($states);
    expect($states)->toBe(['active', 'wait'])->and(count(array_unique(array_column($outcomes, 'pid'))))->toBe(2)
        ->and($this->table('demands')->count())->toBe(1)->and($this->table('demand_scopes')->count())->toBe(1);
});

it('конкурирующие takes не обгоняют eligible FIFO даже при более раннем старте младшего worker', function (): void {
    $one = $this->demandOperation();
    $two = $this->demandOperation();
    $a = $this->enqueueDemand($one);
    $b = $this->enqueueDemand($two);
    $this->demandMonitor->beginTransaction();
    $this->demandMonitor->query('SELECT id FROM pxm_budgets FOR UPDATE')->fetchAll();
    $outcomes = releaseDemandWorkers($this, [demandWorker($this, $two, 'take', 'two'), demandWorker($this, $one, 'take', 'one')]);
    expect(count(array_unique(array_column($outcomes, 'pid'))))->toBe(2)
        ->and($this->table('demands')->where('id', $a->demandId->value)->value('state'))->toBe('admitted');
    $result = $this->takeDemand($b, $two);
    expect($result)->toBeInstanceOf(DemandTicket::class)->and($result->state)->toBe(DemandState::Admitted);
    $order = $this->table('demands')->orderBy('admitted_at')->orderBy('sequence')->pluck('id')->all();
    expect($order)->toBe([$a->demandId->value, $b->demandId->value]);
});

it('cancellation головы и competing take завершаются без deadlock и дают прогресс следующему', function (): void {
    $one = $this->demandOperation();
    $two = $this->demandOperation();
    $a = $this->enqueueDemand($one);
    $b = $this->enqueueDemand($two);
    $this->demandMonitor->beginTransaction();
    $this->demandMonitor->query('SELECT id FROM pxm_budgets FOR UPDATE')->fetchAll();
    releaseDemandWorkers($this, [demandWorker($this, $two, 'take', 'two'), demandWorker($this, $one, 'cancel', 'one', demandId: $a->demandId->value)]);
    expect($this->table('demands')->where('id', $a->demandId->value)->value('state'))->toBe('cancelled')
        ->and($this->takeDemand($b, $two)->state)->toBe(DemandState::Admitted);
});

it('просроченный во время budget lock ticket не получает admission', function (): void {
    $this->queue = $this->queueWithLimits(new QueueLimits(ticketTtlMs: 250));
    $intent = $this->demandOperation();
    $ticket = $this->enqueueDemand($intent);
    $this->demandMonitor->beginTransaction();
    $this->demandMonitor->query('SELECT id FROM pxm_budgets FOR UPDATE')->fetchAll();
    $worker = demandWorker($this, $intent, 'take', 'one');
    // Пока parent держит budget, worker ждёт SQL lock. Отдельный DB clock пересекает TTL.
    $result = releaseDemandWorkers($this, [$worker], fn () => $this->db->select('SELECT pg_sleep(0.3)'))[0];
    expect($result['status'])->toBe('expired')->and($this->table('demands')->where('id', $ticket->demandId->value)->value('admitted_at'))->toBeNull();
});
