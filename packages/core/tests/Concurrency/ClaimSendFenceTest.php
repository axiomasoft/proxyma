<?php

declare(strict_types=1);

use Proxyma\Application\Admission\DemandQueue;
use Proxyma\Application\Admission\ReservationService;
use Proxyma\Domain\Admission\QueueLimits;
use Proxyma\Tests\Support\CatalogTestCase;
use Proxyma\Tests\Support\ClaimFixtures;
use Proxyma\Tests\Support\DemandFixtures;
use Proxyma\Tests\Support\ReservationFixtures;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Support/CatalogTestCase.php';
require_once __DIR__.'/../Support/SelectionFixtures.php';
require_once __DIR__.'/../Support/DemandFixtures.php';
require_once __DIR__.'/../Support/ReservationFixtures.php';
require_once __DIR__.'/../Support/ClaimFixtures.php';

uses(CatalogTestCase::class, DemandFixtures::class, ReservationFixtures::class, ClaimFixtures::class)->group('pg');

beforeEach(function (): void {
    $this->initializeClaimFixture();
    $this->barrier = $this->connectionPdo();
    $this->observer = $this->connectionPdo();
    $this->workerSchema = $this->fixtureSchema;
    $this->claimWorkers = [];
});

afterEach(function (): void {
    if ($this->barrier->inTransaction()) {
        $this->barrier->rollBack();
    }
});

function claimWorker($test, array $prepared, string $label, bool $casGate = false, bool $commitGate = false): Process
{
    [$permit, $input] = $prepared;

    $worker = new Process([PHP_BINARY, __DIR__.'/../Support/ClaimWorker.php', json_encode([
        'schema' => $test->workerSchema, 'label' => $label, 'cas_gate' => $casGate, 'commit_gate' => $commitGate,
        'operation' => $input->operation->operationId->value, 'attempt' => $input->request->physicalAttemptId->value,
        'permit' => $permit->permitId->value, 'deadline' => $input->request->deadlineAt->format('U.u'),
        'route' => $input->route->id->value,
    ], JSON_THROW_ON_ERROR)])->setTimeout(15);
    $test->claimWorkers[] = $worker;

    return $worker;
}

function awaitClaimBlock($test, int $count): void
{
    $until = hrtime(true) + 6_000_000_000;
    do {
        $q = $test->observer->prepare("SELECT count(*) FROM pg_stat_activity WHERE application_name LIKE ? AND wait_event_type='Lock'");
        $q->execute(['proxyma-p34-'.$test->workerSchema.'-%']);
        $found = (int) $q->fetchColumn();
        if ($found === $count) {
            break;
        }
        usleep(10000);
    } while (hrtime(true) < $until);
    expect($found)->toBe($count, implode("\n", array_map(fn ($worker): string => $worker->isStarted() ? $worker->getErrorOutput().$worker->getOutput() : '', $test->claimWorkers)));
}

function claimResult(Process $worker): array
{
    $worker->wait();
    expect($worker->getExitCode())->toBe(0, $worker->getErrorOutput());

    return json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('два независимых workers выдают не более одного authorization и одного fake send', function (): void {
    $prepared = $this->prepareClaim();
    $one = claimWorker($this, $prepared, 'one');
    $two = claimWorker($this, $prepared, 'two');
    $this->barrier->beginTransaction();
    $this->barrier->query('SELECT id FROM pxm_operations FOR UPDATE')->fetchAll();
    try {
        $one->start();
        $two->start();
        awaitClaimBlock($this, 2);
        $this->barrier->commit();
        $results = [claimResult($one), claimResult($two)];
        $states = array_column($results, 'status');
        sort($states);
        expect($states)->toBe(['already_claimed', 'sent'])->and(array_sum(array_column($results, 'bytes')))->toBe(1)
            ->and(count(array_unique(array_column($results, 'pid'))))->toBe(2)
            ->and($this->table('attempts')->count())->toBe(1)->and($this->table('operations')->value('authorized_attempts'))->toBe(1);
    } finally {
        if ($this->barrier->inTransaction()) {
            $this->barrier->rollBack();
        }
        foreach ([$one, $two] as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(0.1);
            }
        }
    }
});

it('claim наблюдает credential rotation, membership edit и owner cancel после SQL lock wait', function (string $change): void {
    if ($change === 'credential') {
        $this->proxyClaimFixture();
    }
    $prepared = $this->prepareClaim();
    $before = $this->accounting();
    $this->barrier->beginTransaction();
    $lock = match ($change) {
        'credential' => 'pxm_endpoints', 'membership' => 'pxm_route_profiles', 'owner' => 'pxm_operations'
    };
    $this->barrier->query("SELECT id FROM {$lock} FOR UPDATE")->fetchAll();
    $worker = claimWorker($this, $prepared, 'one');
    try {
        $worker->start();
        awaitClaimBlock($this, 1);
        match ($change) {
            'credential' => $this->barrier->exec('UPDATE pxm_endpoints SET credential_version=2, revision=revision+1'),
            'membership' => $this->barrier->exec('DELETE FROM pxm_route_profile_members'),
            'owner' => $this->barrier->exec("UPDATE pxm_operations SET status='cancelled', revoked_at=clock_timestamp(), revision=revision+1"),
        };
        $this->barrier->commit();
        $result = claimResult($worker);
        expect($result['status'])->not->toBe('sent')->and($result['bytes'])->toBe(0)
            ->and($this->accounting())->toBe($before)->and($this->table('attempts')->count())->toBe(0)
            ->and($this->table('permits')->value('state'))->toBe('reserved');
    } finally {
        if ($this->barrier->inTransaction()) {
            $this->barrier->rollBack();
        }
        if ($worker->isRunning()) {
            $worker->stop(0.1);
        }
    }
})->with(['credential', 'membership', 'owner']);

it('final CAS берёт fresh SQL clock после задержки за locked reads и не оставляет send charge', function (): void {
    $this->app->instance(QueueLimits::class, new QueueLimits(ticketTtlMs: 800));
    $this->queue = $this->app->make(DemandQueue::class);
    $this->reservations = $this->app->make(ReservationService::class);
    $prepared = $this->prepareClaim();
    $before = $this->accounting();
    $this->barrier->beginTransaction();
    $this->barrier->query("SELECT pg_advisory_xact_lock(hashtextextended('fixture:claim-cas-gate', 0))")->fetchAll();
    $worker = claimWorker($this, $prepared, 'one', true);
    try {
        $worker->start();
        awaitClaimBlock($this, 1);
        $deadline = hrtime(true) + 3_000_000_000;
        do {
            $q = $this->observer->prepare('SELECT clock_timestamp() > ?::timestamptz');
            $q->execute([$prepared[0]->expiresAt->format('Y-m-d H:i:s.uP')]);
            $expired = $q->fetchColumn();
            if ($expired) {
                break;
            }
            usleep(10000);
        } while (hrtime(true) < $deadline);
        expect($expired)->toBeTrue();
        $this->barrier->commit();
        $result = claimResult($worker);
        expect($result['status'])->toBe('final_send_cas_denied')->and($result['bytes'])->toBe(0)
            ->and($this->accounting())->toBe($before)->and($this->table('attempts')->count())->toBe(0);
    } finally {
        if ($this->barrier->inTransaction()) {
            $this->barrier->rollBack();
        }
        if ($worker->isRunning()) {
            $worker->stop(0.1);
        }
    }
});

it('worker после commit ждёт за latestStart и не отправляет bytes или refund', function (): void {
    $this->app->instance(QueueLimits::class, new QueueLimits(ticketTtlMs: 800));
    $this->queue = $this->app->make(DemandQueue::class);
    $this->reservations = $this->app->make(ReservationService::class);
    $prepared = $this->prepareClaim();
    $this->barrier->beginTransaction();
    $this->barrier->query("SELECT pg_advisory_xact_lock(hashtextextended('fixture:claim-commit-gate', 0))")->fetchAll();
    $worker = claimWorker($this, $prepared, 'one', commitGate: true);
    try {
        $worker->start();
        awaitClaimBlock($this, 1);
        expect($this->table('attempts')->count())->toBe(1);
        $charged = $this->accounting();
        $until = hrtime(true) + 3_000_000_000;
        do {
            $q = $this->observer->prepare('SELECT clock_timestamp() > ?::timestamptz');
            $q->execute([$prepared[0]->expiresAt->format('Y-m-d H:i:s.uP')]);
            $expired = $q->fetchColumn();
            if ($expired) {
                break;
            }
            usleep(10000);
        } while (hrtime(true) < $until);
        expect($expired)->toBeTrue();
        $this->barrier->commit();
        $result = claimResult($worker);
        expect($result['status'])->toBe('expired')->and($result['bytes'])->toBe(0)->and($this->accounting())->toBe($charged)
            ->and($this->table('permits')->value('state'))->toBe('unknown_after_authorization')
            ->and($this->table('attempts')->value('dispatch_outcome'))->toBe('not_dispatched');
    } finally {
        if ($this->barrier->inTransaction()) {
            $this->barrier->rollBack();
        }
        if ($worker->isRunning()) {
            $worker->stop(0.1);
        }
    }
});
