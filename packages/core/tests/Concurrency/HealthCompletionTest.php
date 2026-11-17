<?php

declare(strict_types=1);

use Proxyma\Tests\Support\CatalogTestCase;
use Proxyma\Tests\Support\ClaimFixtures;
use Proxyma\Tests\Support\DemandFixtures;
use Proxyma\Tests\Support\QuotaFixtures;
use Proxyma\Tests\Support\RecoveryFixtures;
use Proxyma\Tests\Support\ReservationFixtures;
use Symfony\Component\Process\Process;

foreach (['CatalogTestCase', 'SelectionFixtures', 'DemandFixtures', 'ReservationFixtures', 'ClaimFixtures', 'QuotaFixtures', 'RecoveryFixtures'] as $file) {
    require_once __DIR__.'/../Support/'.$file.'.php';
}
uses(CatalogTestCase::class, DemandFixtures::class, ReservationFixtures::class, ClaimFixtures::class, QuotaFixtures::class, RecoveryFixtures::class)->group('pg');
beforeEach(function (): void {
    $this->initializeRecoveryFixture();
    $this->barrier = $this->connectionPdo();
    $this->observer = $this->connectionPdo();
    $this->healthSchema = $this->fixtureSchema;
    $this->healthWorkers = [];
});
afterEach(function (): void {
    if ($this->barrier->inTransaction()) {
        $this->barrier->rollBack();
    }
    foreach ($this->healthWorkers as $worker) {
        if ($worker->isRunning()) {
            $worker->stop(0.1, 9);
        }
    }
});

function healthWorker($test, array $prepared, string $label, string $action = 'complete', int $status = 200): Process
{
    [, $input] = $prepared;
    $worker = new Process([PHP_BINARY, __DIR__.'/../Support/QuotaWorker.php', json_encode([
        'schema' => $test->healthSchema, 'label' => $label, 'mode' => 'reserve', 'cas_gate' => false,
        'recovery_action' => $action, 'receipt_status' => $status,
        'operation' => $input->operation->operationId->value, 'attempt' => $input->request->physicalAttemptId->value,
        'deadline' => $input->request->deadlineAt->format('U.u'), 'route' => $input->selection->routeId->value,
        'class' => $input->request->trafficClass, 'classes' => [], 'method' => $input->request->method, 'idempotency' => $input->request->idempotency->value,
    ], JSON_THROW_ON_ERROR)]);
    $worker->setTimeout(15);
    $test->healthWorkers[] = $worker;
    $worker->start();

    return $worker;
}

function healthAwait($test, Process $worker, string $label, string $table): void
{
    $until = hrtime(true) + 6000000000;
    $blocked = false;
    do {
        $q = $test->observer->prepare("SELECT query FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock'");
        $q->execute(['proxyma-p42-'.$test->healthSchema.'-'.$label]);
        $query = $q->fetchColumn();
        if (is_string($query) && str_contains($query, $table)) {
            $blocked = true;
            break;
        }
        usleep(10000);
    } while (hrtime(true) < $until && $worker->isRunning());
    expect($blocked)->toBeTrue($worker->getOutput().$worker->getErrorOutput());
}

function healthResult(Process $worker): array
{
    $worker->wait();
    expect($worker->getExitCode())->toBe(0, $worker->getErrorOutput());

    return json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('completion и reaper сериализуют late receipt, cooldown и release в обоих порядках', function (bool $completeFirst): void {
    $prepared = $this->authorized();
    $this->moveSqlClock('2030-06-01T10:12:00Z');
    $this->barrier->beginTransaction();
    $this->barrier->query('SELECT id FROM pxm_budgets ORDER BY scope_key COLLATE "C",tenant_key COLLATE "C" FOR UPDATE')->fetchAll();
    $one = healthWorker($this, $prepared, 'one', $completeFirst ? 'complete' : 'reconcile', 429);
    healthAwait($this, $one, 'one', 'pxm_budgets');
    $two = healthWorker($this, $prepared, 'two', $completeFirst ? 'reconcile' : 'complete', 429);
    if ($completeFirst) {
        expect(healthResult($two)['page']['locked'])->toBe(0);
    } else {
        healthAwait($this, $two, 'two', 'pxm_operations');
    }
    $this->barrier->rollBack();
    healthResult($one);
    if (! $completeFirst) {
        healthResult($two);
    }
    expect($this->table('permits')->value('state'))->toBe('completed')->and($this->table('observations')->count())->toBe(1)
        ->and($this->table('observation_applications')->count())->toBe(4)
        ->and((int) $this->table('budgets')->sum('inflight'))->toBe(0)->and((int) $this->table('quota_windows')->sum('spent'))->toBe(2)
        ->and($this->table('budgets')->where('scope_key', $this->scopeKey)->value('cooldown_until'))->toStartWith('2030-06-01 10:14:00');
})->with([true, false]);

it('две completion connection дают одну observation и три независимые projection', function (): void {
    $prepared = $this->authorized();
    $this->barrier->beginTransaction();
    $this->barrier->query('SELECT id FROM pxm_budgets ORDER BY scope_key COLLATE "C",tenant_key COLLATE "C" FOR UPDATE')->fetchAll();
    $one = healthWorker($this, $prepared, 'one');
    healthAwait($this, $one, 'one', 'pxm_budgets');
    $two = healthWorker($this, $prepared, 'two');
    healthAwait($this, $two, 'two', 'pxm_operations');
    $this->barrier->rollBack();
    $results = [healthResult($one)['status'], healthResult($two)['status']];
    sort($results);
    expect($results)->toBe(['idempotent_duplicate', 'transitioned_to_completed'])
        ->and($this->table('observations')->count())->toBe(1)->and($this->table('observation_applications')->count())->toBe(3)
        ->and((int) $this->table('budgets')->sum('inflight'))->toBe(0)->and((int) $this->table('quota_windows')->sum('spent'))->toBe(2);
});

it('completion ждёт catalog mutation после root и сохраняет receipt при rotation или retirement', function (bool $rotate): void {
    if ($rotate) {
        $this->proxyClaimFixture();
        $this->table('endpoints')->update(['credential_ref' => null, 'credential_version' => null, 'revision' => 2]);
    }
    $prepared = $this->authorized();
    $this->barrier->beginTransaction();
    if ($rotate) {
        $this->barrier->query('SELECT id FROM pxm_endpoints FOR UPDATE')->fetchAll();
        $this->barrier->exec("UPDATE pxm_endpoints SET credential_ref='fixture:rotated',credential_version=2,revision=revision+1");
    } else {
        $this->barrier->query('SELECT id FROM pxm_targets FOR UPDATE')->fetchAll();
        $this->barrier->exec('UPDATE pxm_targets SET retired_at=clock_timestamp(),revision=revision+1');
    }
    $worker = healthWorker($this, $prepared, 'one', status: $rotate ? 407 : 200);
    healthAwait($this, $worker, 'one', $rotate ? 'pxm_endpoints' : 'pxm_targets');
    $this->barrier->commit();
    expect(healthResult($worker)['status'])->toBe('transitioned_to_completed')->and($this->table('observations')->count())->toBe(1)
        ->and($this->table('route_health')->value('state'))->toBe('closed')
        ->and((int) $this->table('budgets')->sum('inflight'))->toBe(0)->and((int) $this->table('quota_windows')->sum('spent'))->toBe(2);
    if ($rotate) {
        expect((int) $this->table('endpoints')->value('credential_version'))->toBe(2)
            ->and($this->table('route_health')->value('reason_code'))->toBeNull();
    } else {
        expect($this->table('targets')->value('retired_at'))->not->toBeNull();
    }
})->with([true, false]);
