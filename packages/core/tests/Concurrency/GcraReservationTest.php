<?php

declare(strict_types=1);

use Proxyma\Contracts\PolicyCommands;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Execution\RouteSelection;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\PolicyScope;
use Proxyma\Domain\Policy\UpdatePolicyCommand;
use Proxyma\Tests\Support\CatalogTestCase;
use Proxyma\Tests\Support\DemandFixtures;
use Proxyma\Tests\Support\ReservationFixtures;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Support/CatalogTestCase.php';
require_once __DIR__.'/../Support/SelectionFixtures.php';
require_once __DIR__.'/../Support/DemandFixtures.php';
require_once __DIR__.'/../Support/ReservationFixtures.php';

uses(CatalogTestCase::class, DemandFixtures::class, ReservationFixtures::class);

beforeEach(function (): void {
    $this->initializeReservationFixture();
    $this->barrier = $this->connectionPdo();
    $this->observer = $this->connectionPdo();
    $this->workerSchema = $this->fixtureSchema;
});

function gcraWorker($test, array $intent, string $label, string $mode = 'reserve', ?string $head = null): Process
{
    return (new Process(['php', dirname(__DIR__).'/Support/ReservationWorker.php', json_encode([
        'schema' => $test->workerSchema, 'mode' => $mode, 'label' => $label, 'head' => $head,
        'operation' => $intent['operation']->operationId->value, 'attempt' => $intent['request']->physicalAttemptId->value,
        'deadline' => $intent['request']->deadlineAt->format('U.u'), 'route' => $intent['route']->routeId->value,
    ], JSON_THROW_ON_ERROR)]))->setTimeout(15);
}

function gcraWorkers($test, array $workers, bool $ordered = false, ?Closure $beforeRelease = null): array
{
    $test->barrier->beginTransaction();
    $test->barrier->query('SELECT id FROM pxm_budgets ORDER BY scope_key COLLATE "C" FOR UPDATE')->fetchAll();
    try {
        try {
            foreach ($workers as $index => $worker) {
                $worker->start();
                if (! $ordered && $index < count($workers) - 1) {
                    continue;
                }
                $deadline = hrtime(true) + 6_000_000_000;
                do {
                    $q = $test->observer->prepare("SELECT count(*) FROM pg_stat_activity WHERE application_name LIKE ? AND wait_event_type = 'Lock'");
                    $q->execute(['proxyma-p33-'.$test->workerSchema.'-%']);
                    $waiting = (int) $q->fetchColumn();
                    if ($waiting === $index + 1) {
                        break;
                    }
                    usleep(10000);
                } while (hrtime(true) < $deadline);
                expect($waiting)->toBe($index + 1, implode("\n", array_map(fn ($w) => $w->isStarted() ? $w->getErrorOutput() : '', $workers)));
            }
            if ($beforeRelease !== null) {
                $beforeRelease();
            }
        } finally {
            $test->barrier->rollBack();
        }
        $results = [];
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->getExitCode())->toBe(0, $worker->getErrorOutput());
            $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    } finally {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(0.1);
            }
        }
    }
}

it('два workers не oversubscribe ни один из трёх scopes и не оставляют subset spend', function (): void {
    $one = $this->demandOperation();
    $two = $this->demandOperation();
    foreach ([$one, $two] as $intent) {
        $this->reservations->enqueue($this->reservationInput($intent), $this->work);
    }
    $results = gcraWorkers($this, [gcraWorker($this, $one, 'one'), gcraWorker($this, $two, 'two')]);
    $states = array_column($results, 'status');
    sort($states);
    expect($states)->toBe(['reserved', 'wait'])->and(count(array_unique(array_column($results, 'pid'))))->toBe(2)
        ->and($this->table('permits')->count())->toBe(1)->and($this->table('reservations')->count())->toBe(3);
    foreach ($this->accounting() as $budget) {
        expect($budget['inflight'])->toBe(1)->and($budget['send_tat'])->toBe('-infinity');
    }
});

it('duplicate reserve из независимых процессов сохраняет один permit и один набор lease refs', function (): void {
    $intent = $this->demandOperation();
    $this->reservations->enqueue($this->reservationInput($intent), $this->work);
    $results = gcraWorkers($this, [gcraWorker($this, $intent, 'one'), gcraWorker($this, $intent, 'two')]);
    expect(array_column($results, 'status'))->toBe(['reserved', 'reserved'])
        ->and($results[0]['permit'])->toBe($results[1]['permit'])->and($this->table('permits')->count())->toBe(1)
        ->and($this->table('reservations')->count())->toBe(3);
    foreach ($this->accounting() as $budget) {
        expect($budget['inflight'])->toBe(1);
    }
});

it('shared egress ограничивает разные routes без spend проигравшего route scope', function (): void {
    $other = selectionAddRoutes($this->db, $this->tenant, $this->catalog['profile'], $this->catalog['target'], 1)[0];
    $origin = CanonicalOrigin::of('https', 'example.test');
    $this->table('routes')->where('id', $this->catalog['route']->value)->update(['egress_key' => 'pool:shared', 'conservative_pool' => true, 'revision' => 2]);
    $this->table('budgets')->where('scope_key', $origin->egressScopeKey('nat:fixture'))->delete();
    $egressId = $this->budget($origin->egressScopeKey('pool:shared'));
    $this->budget($origin->routeScopeKey($other));
    $this->table('budgets')->where('id', '<>', $egressId)->update(['max_inflight' => 10]);
    $one = $this->demandOperation();
    $two = $this->demandOperation();
    $one['route'] = new RouteSelection($this->catalog['route'], RouteKind::Direct, $this->catalog['profile'], null, 1, EgressIdentity::conservativePool('shared'));
    $two['route'] = new RouteSelection($other, RouteKind::Direct, $this->catalog['profile'], null, 1, EgressIdentity::conservativePool('shared'));
    foreach ([$one, $two] as $intent) {
        $this->reservations->enqueue($this->reservationInput($intent), $this->work);
    }
    $results = gcraWorkers($this, [gcraWorker($this, $one, 'one'), gcraWorker($this, $two, 'two')]);
    $states = array_column($results, 'status');
    sort($states);
    expect($states)->toBe(['reserved', 'wait'])->and($this->table('budgets')->where('id', $egressId)->value('inflight'))->toBe(1)
        ->and($this->table('reservations')->count())->toBe(3);
    $loser = $results[0]['status'] === 'wait' ? $one : $two;
    $row = $this->table('budgets')->where('scope_key', $origin->routeScopeKey($loser['route']->routeId))->first();
    expect((int) $row->inflight)->toBe(0)->and($row->tat)->toBe('-infinity');
});

it('reserve держит ранний catalog или policy lock до завершения budget transaction', function (string $kind): void {
    $head = null;
    if ($kind === 'policy') {
        $head = Uuid::random();
        $this->app->make(PolicyCommands::class)->update(new UpdatePolicyCommand($head, $this->tenant, PolicyScope::Tenant, 'acme',
            '{"schema_version":1,"operations":{}}', $this->actor()));
    }
    $intent = $this->demandOperation();
    $input = $this->reservationInput($intent, $head === null ? [] : ['tenant_db:tenant:acme' => 1]);
    $this->reservations->enqueue($input, $this->work);
    $results = gcraWorkers($this, [gcraWorker($this, $intent, 'one', head: $head?->value), gcraWorker($this, $intent, 'two', $kind, $head?->value)], ordered: true,
        beforeRelease: function () use ($kind): void {
            $q = $this->observer->prepare("SELECT query FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'");
            $q->execute(['proxyma-p33-'.$this->workerSchema.'-two']);
            expect(strtolower($q->fetchColumn()))->toContain($kind === 'catalog' ? 'pxm_route_profiles' : 'pxm_policy_heads');
        });
    expect($results[0]['status'])->toBe('reserved')->and($results[1]['status'])->toBe('edited')->and($results[1]['revision'])->toBe(2)
        ->and($this->table('permits')->count())->toBe(1);
})->with(['catalog', 'policy']);
