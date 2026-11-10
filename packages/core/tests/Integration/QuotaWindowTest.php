<?php

declare(strict_types=1);

use Proxyma\Application\Admission\ClaimSend;
use Proxyma\Application\Admission\TrafficSharePolicy;
use Proxyma\Application\Admission\TransactionCoordinator;
use Proxyma\Contracts\CandidateSnapshots;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\DemandWait;
use Proxyma\Domain\Admission\NeedsReplan;
use Proxyma\Domain\Budget\FixedWindow;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Execution\DemandPurpose;
use Proxyma\Domain\Execution\RouteSelection;
use Proxyma\Domain\Execution\ScheduledPermit;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Infrastructure\Database\DatabaseClock;
use Proxyma\Infrastructure\Database\QuotaRepository;
use Proxyma\Tests\Support\CatalogTestCase;
use Proxyma\Tests\Support\ClaimFixtures;
use Proxyma\Tests\Support\DemandFixtures;
use Proxyma\Tests\Support\QuotaFixtures;
use Proxyma\Tests\Support\ReservationFixtures;

require_once __DIR__.'/../Support/CatalogTestCase.php';
require_once __DIR__.'/../Support/SelectionFixtures.php';
require_once __DIR__.'/../Support/DemandFixtures.php';
require_once __DIR__.'/../Support/ReservationFixtures.php';
require_once __DIR__.'/../Support/ClaimFixtures.php';
require_once __DIR__.'/../Support/QuotaFixtures.php';

uses(CatalogTestCase::class, DemandFixtures::class, ReservationFixtures::class, ClaimFixtures::class, QuotaFixtures::class);
beforeEach(function (): void {
    $this->initializeQuotaFixture();
});

it('резервирует hour/day вместе и переводит consume/cancel ровно один раз', function (): void {
    $this->quota->configure($this->tenant, $this->originBudget, 3, 5, 1);
    [$permit, $input, $wire] = $this->quotaPrepared();
    expect($this->table('quota_reservations')->where('state', 'reserved')->count())->toBe(2);
    $candidate = $this->claims->claim($permit, $input, $wire);
    $this->emit($this->claims->activate($candidate));
    expect($this->table('quota_reservations')->where('state', 'consumed')->count())->toBe(2);
    expect(fn () => $this->claims->claim($permit, $input, $wire))->toThrow(AdmissionRejected::class, 'already_claimed');
    [$second, $secondInput] = $this->quotaPrepared();
    $tat = $this->table('budgets')->orderBy('id')->pluck('tat')->all();
    $this->reservations->cancel($this->tenant, $secondInput->operation->operationId, $second->permitId);
    $after = $this->quotaCounts();
    $this->reservations->cancel($this->tenant, $secondInput->operation->operationId, $second->permitId);
    expect($this->quotaCounts())->toBe($after)->and($this->table('budgets')->orderBy('id')->pluck('tat')->all())->toBe($tat)
        ->and($this->table('quota_reservations')->where('state', 'released')->count())->toBe(2)->and($this->emitted)->toBe(1);
    foreach ($after as $row) {
        expect($row['reserved'])->toBe(0)->and($row['spent'])->toBe(in_array($row['id'], $this->table('quota_reservations')->where('state', 'consumed')->pluck('window_id')->all()) ? 1 : 0);
    }
    $this->rejectedSql(fn () => $this->table('quota_reservations')->where('state', 'consumed')->update(['state' => 'released']));
    $this->rejectedSql(fn () => $this->table('quota_windows')->update(['policy_key' => 'another']));
});

it('нулевая квота запрещает, отсутствие optional квоты сохраняет rate/concurrency', function (?int $hour, ?int $day): void {
    $this->quota->configure($this->tenant, $this->originBudget, $hour, $day, 1);
    $before = $this->accounting();
    [$permit] = $this->quotaPrepared();
    if ($hour === 0 || $day === 0) {
        expect($permit)->toBeInstanceOf(DemandWait::class)->and($permit->reason)->toBe('quota_exhausted')->and($this->accounting())->toBe($before);
    } else {
        expect($this->table('reservations')->count())->toBe(3)->and($this->table('quota_reservations')->count())->toBe(0);
    }
    expect(fn () => $this->quota->configure($this->tenant, $this->originBudget, -1, null, 1))->toThrow(AdmissionRejected::class, 'invalid_quota_limit');
})->with([[0, null], [null, 0], [null, null]]);

it('понижение лимита сохраняет usage, identity и GCRA debts, старый permit не расходует quota', function (): void {
    $this->quota->configure($this->tenant, $this->originBudget, 5, 5, 1);
    for ($i = 0; $i < 2; $i++) {
        [$p,$in,$w] = $this->quotaPrepared();
        $this->claims->claim($p, $in, $w);
    }
    [$pending,$input,$wire] = $this->quotaPrepared();
    $before = $this->accounting();
    $ids = $this->table('quota_windows')->orderBy('id')->pluck('id')->all();
    $this->quota->configure($this->tenant, $this->originBudget, 1, 1, 2);
    expect($this->accounting())->toBe($before)->and($this->table('quota_windows')->orderBy('id')->pluck('id')->all())->toBe($ids);
    expect(fn () => $this->claims->claim($pending, $input, $wire))->toThrow(AdmissionRejected::class);
    expect($this->table('quota_windows')->where('spent', 2)->where('reserved', 1)->count())->toBe(2);
    $this->reservations->cancel($this->tenant, $input->operation->operationId, $pending->permitId);
    [$wait,$waiting] = $this->quotaPrepared();
    expect($wait)->toBeInstanceOf(DemandWait::class)->and($this->table('quota_windows')->where('spent', 2)->where('reserved', 0)->count())->toBe(2);
    $this->quota->configure($this->tenant, $this->originBudget, null, null, 3);
    $head = $this->table('demands')->where('operation_id', $waiting->operation->operationId->value)->first();
    $this->queue->cancel($this->tenant, $waiting->operation->operationId, new Uuid($head->id));
    [$fresh] = $this->quotaPrepared();
    expect($fresh)->toBeInstanceOf(ScheduledPermit::class)
        ->and($this->table('quota_windows')->where('spent', 2)->count())->toBe(2);
});

it('отсутствующее materialized окно закрывает admission без first-send UPSERT', function (): void {
    $this->quota->configure($this->tenant, $this->originBudget, 2, 2, 1);
    $this->table('quota_windows')->delete();
    $before = $this->accounting();
    $intent = $this->quotaIntent();
    expect(fn () => $this->quotaPrepared(intent: $intent))->toThrow(AdmissionRejected::class, 'quota_materialization_missing');
    expect($this->accounting())->toBe($before)->and($this->table('quota_windows')->count())->toBe(0);
    $this->quota->provision($this->tenant, [$this->originBudget]);
    [$permit] = $this->quotaPrepared(intent: $intent);
    expect($permit)->toBeInstanceOf(ScheduledPermit::class);
});

it('UTC rollover освобождает старый permit и требует новую physical attempt', function (bool $composable): void {
    $this->sqlClock('2030-01-01T23:59:59Z');
    $this->db->statement("SET TIME ZONE 'Pacific/Auckland'");
    $this->quota->configure($this->tenant, $this->originBudget, 1, 1, 1);
    [$permit,$input,$wire] = $this->quotaPrepared();
    expect($permit->expiresAt->format('c'))->toBe('2030-01-02T00:00:00+00:00');
    $tat = $this->table('budgets')->orderBy('id')->pluck('tat')->all();
    $this->moveSqlClock('2030-01-02T00:00:01Z');
    $this->db->statement('CREATE TABLE fixture_quota_finance (reserved int NOT NULL)');
    $this->db->statement('INSERT INTO fixture_quota_finance VALUES (0)');
    try {
        if ($composable) {
            $this->db->transaction(fn () => $this->transactions->locked($this->db, function ($tx) use ($permit, $input, $wire): void {
                $this->db->table('fixture_quota_finance')->increment('reserved');
                $this->claims->claimSendLocked($permit, $input, $wire, $tx);
            }));
        } else {
            $this->claims->claim($permit, $input, $wire);
        }
        throw new RuntimeException('Expected NeedsReplan.');
    } catch (NeedsReplan $replan) {
        expect($replan->released)->toBeTrue();
    }
    expect($this->db->transactionLevel())->toBe(0)->and($this->db->table('fixture_quota_finance')->value('reserved'))->toBe(0)
        ->and((int) $this->table('quota_windows')->sum('reserved'))->toBe(0)->and((int) $this->table('quota_windows')->sum('spent'))->toBe(0)
        ->and($this->table('budgets')->orderBy('id')->pluck('tat')->all())->toBe($tat)->and($this->table('attempts')->count())->toBe(0)
        ->and($this->table('permits')->value('state'))->toBe('expired_before_send');
    $request = $this->modifiedWire($input->request, ['physicalAttemptId' => Uuid::random(), 'sealDigest' => null]);
    [$new,$newInput,$newWire] = $this->quotaPrepared(intent: ['operation' => $input->operation, 'request' => $request, 'route' => $input->selection]);
    $this->claims->claim($new, $newInput, $newWire);
    expect($this->table('quota_windows')->where('spent', 1)->count())->toBe(2)->and($this->table('quota_reservations')->where('state', 'released')->count())->toBe(2);
})->with([false, true]);

it('fixed windows не зависят от timezone/DST, неподдерживаемый вид отвергается', function (): void {
    $day = new FixedWindow('day', new DateTimeImmutable('2026-11-01T01:30:00-04:00'));
    expect($day->startsAt->format('c'))->toBe('2026-11-01T00:00:00+00:00')->and($day->endsAt->getTimestamp() - $day->startsAt->getTimestamp())->toBe(86400);
    expect(fn () => new FixedWindow('rolling', new DateTimeImmutable))->toThrow(InvalidArgumentException::class);
});

it('настраиваемый traffic class сохраняет share 18/hour и считает каждую physical authorization', function (): void {
    $this->trafficShares(['background' => 30]);
    expect(TrafficSharePolicy::hourlyLimit(60000000, 30))->toBe(18);
    $this->table('budgets')->where('id', $this->originBudget->value)->update(['emission_interval_us' => 60000000]);
    $this->quota->configure($this->tenant, $this->originBudget, 60, null, 1);
    $child = $this->quota->configureTrafficShare($this->tenant, $this->originBudget, CanonicalOrigin::of('https', 'example.test'), 'background', 2);
    for ($i = 0; $i < 18; $i++) {
        [$permit,$input,$wire] = $this->quotaPrepared('background');
        $this->emit($this->claims->activate($this->claims->claim($permit, $input, $wire)));
    }
    $before = $this->accounting();
    [$wait] = $this->quotaPrepared('background');
    expect($wait)->toBeInstanceOf(DemandWait::class)->and($wait->reason)->toBe('quota_exhausted')->and($this->accounting())->toBe($before)
        ->and($this->emitted)->toBe(18)->and($this->table('attempts')->count())->toBe(18)
        ->and($this->table('quota_windows')->where('budget_id', $child->value)->max('spent'))->toBe(18)
        ->and($this->table('quota_windows')->where('budget_id', $this->originBudget->value)->max('spent'))->toBe(18);
    expect(fn () => $this->quota->configureTrafficShare($this->tenant, $this->originBudget, $input->request->url->origin, 'unregistered', 2))->toThrow(AdmissionRejected::class);
});

it('probe и traffic share не исключают обязательные origin hour/day quotas', function (): void {
    $this->trafficShares(['background' => 30]);
    $probe = new Uuid($this->budget(CanonicalOrigin::of('https', 'example.test')->probeScopeKey()));
    $this->quota->configure($this->tenant, $this->originBudget, 1, 1, 1);
    $this->quota->configure($this->tenant, $probe, 1, 1, 1);
    $this->quota->configureTrafficShare($this->tenant, $this->originBudget, CanonicalOrigin::of('https', 'example.test'), 'background', 2);
    $intent = $this->quotaIntent('background', DemandPurpose::Probe);
    [$permit,$input,$wire] = $this->quotaPrepared('background', $intent);
    $this->claims->claim($permit, $input, $wire);
    expect($this->table('quota_reservations')->where('state', 'consumed')->count())->toBe(5);
    [$wait] = $this->quotaPrepared('background');
    expect($wait)->toBeInstanceOf(DemandWait::class)->and($this->table('quota_windows')->where('spent', 1)->count())->toBe(5);
});

it('рост route pool не умножает origin quota', function (): void {
    $this->quota->configure($this->tenant, $this->originBudget, 1, null, 1);
    [$p,$in,$w] = $this->quotaPrepared();
    $this->claims->claim($p, $in, $w);
    $ids = $this->table('quota_windows')->orderBy('id')->pluck('id')->all();
    $routes = selectionAddRoutes($this->db, $this->tenant, $this->catalog['profile'], $this->catalog['target'], 10);
    $this->budget($in->request->url->origin->egressScopeKey('pool:shared'));
    $this->table('route_profiles')->update(['revision' => 2]);
    $intent = $this->quotaIntent();
    $intent['route'] = new RouteSelection($routes[0], RouteKind::Direct, $this->catalog['profile'], null, 1,
        EgressIdentity::conservativePool('shared'));
    $before = $this->accounting();
    [$wait] = $this->quotaPrepared(intent: $intent);
    expect($wait)->toBeInstanceOf(DemandWait::class)->and($this->table('routes')->count())->toBe(11)->and($this->accounting())->toBe($before)
        ->and($this->table('quota_windows')->orderBy('id')->pluck('id')->all())->toBe($ids)->and((int) $this->table('quota_windows')->sum('spent'))->toBe(1);
});

it('system и tenant quotas применяются одновременно, denial не тратит свободный scope', function (): void {
    $this->quota->configure($this->tenant, $this->originBudget, 2, 2, 1);
    $system = Uuid::random();
    $this->db->transaction(function () use ($system): void {
        $this->table('budgets')->insert(['id' => $system->value, 'tenant_key' => 'system', 'scope_key' => $this->scopeKey,
            'emission_interval_us' => 1, 'burst' => 100, 'max_inflight' => 100, 'hour_limit' => 1, 'day_limit' => 1]);
        $row = $this->table('budgets')->where('id', $system->value)->lockForUpdate()->first();
        QuotaRepository::provisionWindows($this->db, $row, DatabaseClock::now($this->db));
    });
    [$p,$in,$w] = $this->quotaPrepared();
    $this->claims->claim($p, $in, $w);
    expect($this->table('quota_reservations')->where('state', 'consumed')->count())->toBe(4);
    $before = $this->accounting();
    $windows = $this->quotaCounts();
    [$wait] = $this->quotaPrepared();
    expect($wait)->toBeInstanceOf(DemandWait::class)->and($this->accounting())->toBe($before)->and($this->quotaCounts())->toBe($windows);
});

it('eligible класс обходит quota-blocked FIFO head в пределах bounded skip', function (): void {
    $this->trafficShares(['background' => 0, 'interactive' => 100]);
    $this->quota->configure($this->tenant, $this->originBudget, 10, null, 1);
    $origin = CanonicalOrigin::of('https', 'example.test');
    foreach (['background', 'interactive'] as $class) {
        $this->quota->configureTrafficShare($this->tenant, $this->originBudget, $origin, $class, 2);
    }
    [$blocked] = $this->quotaPrepared('background');
    [$eligible] = $this->quotaPrepared('interactive');
    expect($blocked)->toBeInstanceOf(DemandWait::class)->and($eligible)->toBeInstanceOf(ScheduledPermit::class);
});

it('quota configuration versioned, audited и idempotent; конфликт не даёт partial mutation', function (): void {
    $actor = $this->actor(1, 'quota-configuration-fixture');
    $this->quota->configure($this->tenant, $this->originBudget, 5, 7, 1, $actor);
    $windows = $this->quotaCounts();
    $before = $this->accounting();
    $this->quota->configure($this->tenant, $this->originBudget, 5, 7, 1, $actor);
    expect($this->quotaCounts())->toBe($windows)->and($this->accounting())->toBe($before)
        ->and($this->table('audit_events')->where('entity_type', 'budgets')->count())->toBe(1);
    expect(fn () => $this->quota->configure($this->tenant, $this->originBudget, 6, 7, 1, $actor))->toThrow(AdmissionRejected::class, 'quota_idempotency_conflict');
    $invalid = new CatalogMutationActor($actor->actor, 'missing expected revision', null, 'invalid-quota-config');
    expect(fn () => $this->quota->configure($this->tenant, $this->originBudget, 1, 1, 2, $invalid))->toThrow(AdmissionRejected::class, 'invalid_quota_actor');
    expect($this->quotaCounts())->toBe($windows)->and($this->accounting())->toBe($before);
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_10_000008_add_fixed_window_quotas.php';
    expect(fn () => $migration->down())->toThrow(LogicException::class, 'history');
});

it('quota configuration не сбрасывает cooldown или health hold', function (): void {
    $until = DatabaseClock::now($this->db)->modify('+1 hour')->format('Y-m-d H:i:s.uP');
    $this->table('route_health')->update(['state' => 'open', 'open_until' => $until, 'health_epoch' => 2, 'state_version' => 2]);
    $before = $this->table('route_health')->get()->map(fn ($row) => (array) $row)->all();
    $this->quota->configure($this->tenant, $this->originBudget, 5, 7, 1);
    expect($this->table('route_health')->get()->map(fn ($row) => (array) $row)->all())->toBe($before);
    expect(fn () => $this->quotaPrepared())->toThrow(AdmissionRejected::class, 'health_unavailable');
    expect($this->table('quota_reservations')->count())->toBe(0)->and($this->table('attempts')->count())->toBe(0);
});

it('два UTC дня имеют отдельные usage без списания сегодняшнего send во вчерашнее окно', function (): void {
    $this->sqlClock('2030-12-31T23:59:59Z');
    $this->quota->configure($this->tenant, $this->originBudget, 1, 1, 1);
    [$p,$in,$w] = $this->quotaPrepared();
    $this->claims->claim($p, $in, $w);
    $this->moveSqlClock('2031-01-01T00:00:01Z');
    [$p,$in,$w] = $this->quotaPrepared();
    $this->claims->claim($p, $in, $w);
    expect($this->table('quota_windows')->where('spent', 1)->count())->toBe(4)
        ->and((int) $this->table('quota_windows')->sum('spent'))->toBe(4)->and($this->table('attempts')->count())->toBe(2);
    $this->rejectedSql(fn () => $this->table('budgets')->where('id', $this->originBudget->value)->update(['quota_policy_key' => 'fresh-counter']));
});

it('authorized unknown после latestStart не возвращает consumed quota', function (): void {
    $this->quota->configure($this->tenant, $this->originBudget, 1, 1, 1);
    $now = hrtime(true);
    $coordinator = new TransactionCoordinator($this->transactions->scope, function () use (&$now): int {
        return $now;
    });
    $this->app->instance(TransactionCoordinator::class, $coordinator);
    $this->app->forgetInstance(ClaimSend::class);
    $this->claims = $this->app->make(ClaimSend::class);
    [$permit,$input,$wire] = $this->quotaPrepared();
    $candidate = $this->claims->claim($permit, $input, $wire);
    $counts = $this->quotaCounts();
    $now = $candidate->conservativeLatestStartNs($input->request->timeouts->totalWithCleanupMarginNs()) + 1;
    expect(fn () => $this->claims->activate($candidate))->toThrow(LogicException::class, 'expired');
    expect($this->quotaCounts())->toBe($counts)->and($this->table('quota_reservations')->where('state', 'consumed')->count())->toBe(2)
        ->and($this->table('permits')->value('state'))->toBe('unknown_after_authorization')->and($this->emitted)->toBe(0);
    [$wait] = $this->quotaPrepared();
    expect($wait)->toBeInstanceOf(DemandWait::class)->and($wait->reason)->toBe('quota_exhausted');
});

it('selector показывает advisory quota hold и не выдаёт authority', function (): void {
    $this->quota->configure($this->tenant, $this->originBudget, 0, null, 1);
    $before = $this->quotaCounts();
    $snapshot = $this->app->make(CandidateSnapshots::class)->load($this->tenant, $this->catalog['profile'], $this->catalog['target'],
        selectionPolicy($this->tenant, $this->catalog['profile'], $this->catalog['target']));
    expect($snapshot->candidates[0]->budgetReady)->toBeFalse()->and($snapshot->candidates[0]->exclusions)->toContain('quota_exhausted')
        ->and($this->quotaCounts())->toBe($before)->and($this->table('permits')->count())->toBe(0);
});

it('upgrade сохраняет legacy window usage, долги и consumed факт после released lease', function (): void {
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_10_000008_add_fixed_window_quotas.php';
    $migration->down();
    $now = DatabaseClock::now($this->db);
    $window = new FixedWindow('hour', $now);
    $windowId = Uuid::random();
    $this->table('quota_windows')->insert(['id' => $windowId->value, 'tenant_key' => 'acme', 'budget_id' => $this->originBudget->value,
        'starts_at' => $window->startsAt->format('Y-m-d H:i:s.uP'), 'ends_at' => $window->endsAt->format('Y-m-d H:i:s.uP'),
        'quota_limit' => 5, 'reserved' => 1, 'spent' => 1]);
    foreach (['reserved', 'unknown_after_authorization'] as $state) {
        $operation = $this->operation($this->catalog);
        $demand = Uuid::random();
        $permit = Uuid::random();
        $physical = Uuid::random();
        $this->table('demands')->insert(['id' => $demand->value, 'tenant_key' => 'acme', 'operation_id' => $operation->value,
            'profile_id' => $this->catalog['profile']->value, 'target_id' => $this->catalog['target']->value, 'purpose' => 'normal',
            'cost' => 1, 'deadline' => $now->modify('+1 hour')->format('Y-m-d H:i:s.uP')]);
        $authorized = $state === 'reserved' ? null : $now->modify('-5 seconds')->format('Y-m-d H:i:s.uP');
        $this->table('permits')->insert(['id' => $permit->value, 'tenant_key' => 'acme', 'operation_id' => $operation->value, 'demand_id' => $demand->value,
            'route_id' => $this->catalog['route']->value, 'target_id' => $this->catalog['target']->value, 'physical_attempt_id' => $physical->value,
            'wire_fingerprint' => str_repeat('a', 64), 'state' => $state, 'cost' => 1, 'not_before' => $now->modify('-10 seconds')->format('Y-m-d H:i:s.uP'),
            'expires_at' => $now->modify('+30 seconds')->format('Y-m-d H:i:s.uP'), 'authorized_at' => $authorized, 'claim_id' => $authorized === null ? null : Uuid::random()->value]);
        $this->table('reservations')->insert(['tenant_key' => 'acme', 'permit_id' => $permit->value, 'budget_id' => $this->originBudget->value,
            'quota_window_id' => $windowId->value, 'cost' => 1, 'state' => $authorized === null ? 'reserved' : 'released']);
        if ($authorized !== null) {
            $this->table('attempts')->insert(['tenant_key' => 'acme', 'operation_id' => $operation->value, 'permit_id' => $permit->value,
                'physical_attempt_id' => $physical->value, 'wire_fingerprint' => str_repeat('a', 64), 'status' => $state, 'authorized_at' => $authorized, 'snapshot' => '{}']);
        }
    }
    $this->table('budgets')->where('id', $this->originBudget->value)->update(['inflight' => 1, 'tat' => $now->modify('+1 minute')->format('Y-m-d H:i:s.uP'),
        'send_tat' => $now->format('Y-m-d H:i:s.uP')]);
    $debts = $this->accounting();
    $this->db->transaction(fn () => $migration->up());
    expect($this->accounting())->toBe($debts)->and((int) $this->table('quota_windows')->sum('reserved'))->toBe(1)
        ->and((int) $this->table('quota_windows')->sum('spent'))->toBe(1)
        ->and($this->table('quota_reservations')->where('state', 'reserved')->count())->toBe(1)
        ->and($this->table('quota_reservations')->where('state', 'consumed')->count())->toBe(1)
        ->and($this->table('budgets')->where('id', $this->originBudget->value)->value('hour_limit'))->toBe(5);
    expect(fn () => $migration->down())->toThrow(LogicException::class, 'history');
});

it('удаление class из config не обходит materialized mandatory share', function (): void {
    $this->trafficShares(['background' => 0]);
    $this->quota->configure($this->tenant, $this->originBudget, 10, null, 1);
    $this->quota->configureTrafficShare($this->tenant, $this->originBudget, CanonicalOrigin::of('https', 'example.test'), 'background', 2);
    $this->trafficShares([]);
    $before = $this->accounting();
    expect(fn () => $this->quotaPrepared('background'))->toThrow(AdmissionRejected::class, 'traffic_policy_changed');
    expect($this->accounting())->toBe($before)->and($this->table('permits')->count())->toBe(0);
});

it('включение и повторное включение quota учитывает authorization из периода без лимита', function (): void {
    [$permit, $input, $wire] = $this->quotaPrepared();
    $this->claims->claim($permit, $input, $wire);
    $this->quota->configure($this->tenant, $this->originBudget, 1, null, 1);
    $window = $this->table('quota_windows')->where('spent', 1)->first();
    expect($window)->not->toBeNull()->and($this->table('quota_reservations')->where('state', 'consumed')->count())->toBe(1);

    $this->quota->configure($this->tenant, $this->originBudget, null, null, 2);
    [$permit, $input, $wire] = $this->quotaPrepared();
    $this->claims->claim($permit, $input, $wire);
    $this->quota->configure($this->tenant, $this->originBudget, 1, null, 3);
    expect($this->table('quota_windows')->where('id', $window->id)->value('spent'))->toBe(2)
        ->and($this->table('quota_reservations')->where('state', 'consumed')->count())->toBe(2);
    [$wait] = $this->quotaPrepared();
    expect($wait)->toBeInstanceOf(DemandWait::class)->and($wait->reason)->toBe('quota_exhausted');
});

it('новый class share сохраняет named history и запрещает старый permit с неполным scope set', function (): void {
    $this->table('budgets')->where('id', $this->originBudget->value)->update(['emission_interval_us' => 60_000_000]);
    for ($i = 0; $i < 2; $i++) {
        [$permit, $input, $wire] = $this->quotaPrepared('background');
        $this->claims->claim($permit, $input, $wire);
    }
    [$pending, $oldInput, $oldWire] = $this->quotaPrepared('background');
    $this->trafficShares(['background' => 30]);
    $child = $this->quota->configureTrafficShare($this->tenant, $this->originBudget, CanonicalOrigin::of('https', 'example.test'), 'background', 1);
    expect((int) $this->table('quota_windows')->where('budget_id', $child->value)->sum('spent'))->toBe(2);
    expect(fn () => $this->claims->claim($pending, $oldInput, $oldWire))->toThrow(AdmissionRejected::class, 'mandatory_budget_set_changed');
    $this->reservations->cancel($this->tenant, $oldInput->operation->operationId, $pending->permitId);
    expect((int) $this->table('quota_windows')->where('budget_id', $child->value)->sum('reserved'))->toBe(0);
    [$permit, $input, $wire] = $this->quotaPrepared('background');
    $this->claims->claim($permit, $input, $wire);
    expect((int) $this->table('quota_windows')->where('budget_id', $child->value)->sum('spent'))->toBe(3)
        ->and($this->table('quota_reservations')->where('state', 'consumed')->count())->toBe(3);
});

it('включение quota подхватывает pending lease и cancel освобождает её ровно один раз', function (): void {
    $this->sqlClock('2030-06-01T10:10:00Z');
    [$permit, $input, $wire] = $this->quotaPrepared();
    $this->quota->configure($this->tenant, $this->originBudget, 1, 1, 1);
    expect((int) $this->table('quota_windows')->sum('reserved'))->toBe(2);
    $before = $this->accounting();
    expect(fn () => $this->claims->claim($permit, $input, $wire))->toThrow(AdmissionRejected::class, 'claim_evidence_changed');
    expect($this->accounting())->toBe($before)->and($this->table('attempts')->count())->toBe(0);
    $this->reservations->cancel($this->tenant, $input->operation->operationId, $permit->permitId);
    $released = $this->quotaCounts();
    $this->reservations->cancel($this->tenant, $input->operation->operationId, $permit->permitId);
    expect((int) $this->table('quota_windows')->sum('reserved'))->toBe(0)->and($this->quotaCounts())->toBe($released)
        ->and($this->table('quota_reservations')->where('state', 'released')->count())->toBe(2);
});

it('traffic share отвергает некорректную конфигурацию до SQL', function (): void {
    foreach ([['bad name' => 20], ['background' => 101], ['background' => -1], ['background' => '30']] as $classes) {
        expect(fn () => new TrafficSharePolicy($classes))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => TrafficSharePolicy::hourlyLimit(0, 30))->toThrow(InvalidArgumentException::class)
        ->and(fn () => TrafficSharePolicy::scope(CanonicalOrigin::of('https', 'example.test'), 'BAD'))->toThrow(InvalidArgumentException::class)
        ->and(TrafficSharePolicy::hourlyLimit(1, 100))->toBe(3_600_000_000);
});
