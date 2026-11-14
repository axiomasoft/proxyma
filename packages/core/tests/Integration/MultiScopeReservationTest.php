<?php

declare(strict_types=1);

use Proxyma\Application\Admission\TransactionCoordinator;
use Proxyma\Application\Health\ProbeCoordinator;
use Proxyma\Contracts\PolicyCommands;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\DemandWait;
use Proxyma\Domain\Admission\ReservationDeferred;
use Proxyma\Domain\Budget\Gcra;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Execution\DemandPurpose;
use Proxyma\Domain\Execution\IdempotencyClass;
use Proxyma\Domain\Execution\LockedOwnerState;
use Proxyma\Domain\Execution\PermitState;
use Proxyma\Domain\Execution\RouteSelection;
use Proxyma\Domain\Execution\ScheduledPermit;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireBody;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Policy\BudgetConstraint;
use Proxyma\Domain\Policy\PolicyScope;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Domain\Policy\UpdatePolicyCommand;
use Proxyma\Infrastructure\Database\BudgetRepository;
use Proxyma\Infrastructure\Database\DatabaseClock;
use Proxyma\Infrastructure\Database\DemandRepository;
use Proxyma\Infrastructure\Database\ReservationSchema;
use Proxyma\Tests\Support\CatalogTestCase;
use Proxyma\Tests\Support\DemandFixtures;
use Proxyma\Tests\Support\ReservationFixtures;

require_once __DIR__.'/../Support/CatalogTestCase.php';
require_once __DIR__.'/../Support/SelectionFixtures.php';
require_once __DIR__.'/../Support/DemandFixtures.php';
require_once __DIR__.'/../Support/ReservationFixtures.php';

uses(CatalogTestCase::class, DemandFixtures::class, ReservationFixtures::class);

beforeEach(function (): void {
    $this->initializeReservationFixture();
});

it('блокирует budget scopes пакетно в каноническом порядке и сохраняет exclusive fence', function (): void {
    $this->budget('batch:z');
    $this->budget('batch:a');
    $this->budget('batch:a', TenantKey::system());
    $rows = $this->table('budgets')->whereIn('scope_key', ['batch:a', 'batch:z'])->get()->all();
    $queries = 0;
    $this->db->beforeExecuting(function (string $sql) use (&$queries): void {
        if (str_contains($sql, 'pxm_budgets') && str_contains(strtolower($sql), 'for update')) {
            $queries++;
        }
    });
    $repository = $this->app->make(DemandRepository::class);
    $locked = $this->app->make(TransactionCoordinator::class)->run(function ($tx) use ($repository, $rows): array {
        $locked = $repository->lockBudgets(array_reverse($rows), $tx);
        $other = $this->connectionPdo();
        $other->beginTransaction();
        try {
            expect(fn () => $other->query("SELECT id FROM pxm_budgets WHERE tenant_key = 'acme' AND scope_key = 'batch:a' FOR UPDATE NOWAIT"))
                ->toThrow(PDOException::class, '55P03');
        } finally {
            $other->rollBack();
        }

        return $locked;
    });
    expect($queries)->toBe(1)->and(array_map(fn ($row) => $row->scope_key."\0".$row->tenant_key, $locked))
        ->toBe(['batch:a'."\0".'acme', 'batch:a'."\0".TenantKey::SYSTEM, 'batch:z'."\0".'acme']);
    $missing = clone $rows[0];
    $missing->scope_key = 'batch:missing';
    expect(fn () => $this->app->make(TransactionCoordinator::class)->run(fn ($tx) => $repository->lockBudgets([$missing], $tx)))
        ->toThrow(AdmissionRejected::class, 'required_state_missing');
    $wrong = clone $rows[0];
    $wrong->id = Uuid::random()->value;
    expect(fn () => $this->app->make(TransactionCoordinator::class)->run(fn ($tx) => $repository->lockBudgets([$wrong], $tx)))
        ->toThrow(AdmissionRejected::class, 'budget_identity_changed');
});

it('сохраняет порядок budget locks между ограниченными пакетами', function (): void {
    $insert = [];
    for ($i = 0; $i < 129; $i++) {
        $insert[] = ['id' => Uuid::random()->value, 'tenant_key' => $this->tenant->value,
            'scope_key' => sprintf('batch:%03d', $i), 'emission_interval_us' => 1, 'burst' => 1, 'max_inflight' => 1];
    }
    $this->table('budgets')->insert($insert);
    $rows = $this->table('budgets')->where('scope_key', 'like', 'batch:%')->orderByDesc('scope_key')->get()->all();
    $queries = 0;
    $this->db->beforeExecuting(function (string $sql) use (&$queries): void {
        if (str_contains($sql, 'pxm_budgets') && str_contains(strtolower($sql), 'for update')) {
            $queries++;
        }
    });
    $locked = $this->app->make(TransactionCoordinator::class)->run(
        fn ($tx) => $this->app->make(DemandRepository::class)->lockBudgets($rows, $tx),
    );
    expect($queries)->toBe(2)->and(array_column($locked, 'scope_key'))->toBe(array_column($insert, 'scope_key'));
});

it('читает все budget debts одним снимком с микросекундной точностью и отказывает при отсутствии строки', function (): void {
    $other = $this->budget('batch:debt');
    $instant = new DateTimeImmutable('2030-06-01T10:10:00.123456Z');
    $this->table('budgets')->where('id', $other)->update([
        'tat' => $instant->format('Y-m-d H:i:s.uP'),
        'send_tat' => $instant->format('Y-m-d H:i:s.uP'),
        'cooldown_until' => $instant->format('Y-m-d H:i:s.uP'),
    ]);
    $rows = $this->table('budgets')->orderBy('scope_key')->get()->all();
    $queries = 0;
    $this->db->beforeExecuting(function (string $sql) use (&$queries): void {
        if (str_contains($sql, 'isfinite(tat)')) {
            $queries++;
        }
    });
    $repository = $this->app->make(BudgetRepository::class);
    $snapshots = $repository->read($rows);
    $expected = (int) $instant->format('Uu');
    expect($queries)->toBe(1)->and($snapshots[$other]['tat'])->toBe($expected)
        ->and($snapshots[$other]['send'])->toBe($expected)->and($snapshots[$other]['cooldown'])->toBe($expected);
    $missing = clone $rows[0];
    $missing->id = Uuid::random()->value;
    expect(fn () => $repository->read([$missing]))->toThrow(AdmissionRejected::class, 'required_state_missing');
});

it('читает большой набор budget debts ограниченными группами без потери строк', function (): void {
    $insert = [];
    for ($i = 0; $i < 513; $i++) {
        $insert[] = ['id' => Uuid::random()->value, 'tenant_key' => $this->tenant->value,
            'scope_key' => sprintf('debts:%03d', $i), 'emission_interval_us' => 1, 'burst' => 1, 'max_inflight' => 1];
    }
    $this->table('budgets')->insert($insert);
    $rows = $this->table('budgets')->where('scope_key', 'like', 'debts:%')->get()->all();
    $queries = 0;
    $this->db->beforeExecuting(function (string $sql) use (&$queries): void {
        if (str_contains($sql, 'isfinite(tat)')) {
            $queries++;
        }
    });
    $snapshots = $this->app->make(BudgetRepository::class)->read($rows);
    expect($queries)->toBe(2)->and(count($snapshots))->toBe(513)
        ->and(array_keys($snapshots))->toBe(array_column($rows, 'id'));
});

it('резервирует origin route egress probe и system scopes одним immutable permit', function (): void {
    $intent = $this->demandOperation(purpose: DemandPurpose::Probe);
    $this->budget($intent['request']->url->origin->probeScopeKey());
    $this->budget($this->scopeKey, TenantKey::system());
    $input = $this->reservationInput($intent);
    $this->table('route_health')->update(['state' => 'unknown']);
    $this->app->make(ProbeCoordinator::class)->claim($input);
    $ticket = $this->reservations->enqueue($input, $this->work);
    $permit = $this->reservations->reserve($ticket, $input);
    expect($permit)->toBeInstanceOf(ScheduledPermit::class)->and($this->table('reservations')->count())->toBe(5);
    foreach ($this->accounting() as $budget) {
        expect($budget['inflight'])->toBe(1)->and($budget['tat'])->not->toBe('-infinity')->and($budget['send_tat'])->toBe('-infinity');
    }
    $before = $this->accounting();
    $again = $this->reservations->reserve($ticket, $input);
    expect($again->permitId->value)->toBe($permit->permitId->value)->and($again->slotAt)->toEqual($permit->slotAt)
        ->and($again->expiresAt)->toEqual($permit->expiresAt)->and($this->accounting())->toBe($before)
        ->and($this->table('permits')->count())->toBe(1);
});

it('при одном заполненном scope не меняет ни один budget demand или permit', function (): void {
    $intent = $this->demandOperation();
    $input = $this->reservationInput($intent);
    $ticket = $this->reservations->enqueue($input, $this->work);
    $egress = $intent['request']->url->origin->egressScopeKey('nat:fixture');
    $this->table('budgets')->where('scope_key', $egress)->update(['inflight' => 1]);
    $before = $this->accounting();
    $result = $this->reservations->reserve($ticket, $input);
    expect($result)->toBeInstanceOf(DemandWait::class)->and($result->reason)->toBe('capacity_wait')
        ->and($this->accounting())->toBe($before)->and($this->table('permits')->count())->toBe(0)
        ->and($this->table('reservations')->count())->toBe(0)->and($this->table('demands')->value('state'))->toBe('active');
});

it('откатывает caller finance mutation при typed wait после полного lock set', function (): void {
    $intent = $this->demandOperation();
    $input = $this->reservationInput($intent);
    $ticket = $this->reservations->enqueue($input, $this->work);
    $this->table('budgets')->where('scope_key', $this->scopeKey)->update(['inflight' => 1]);
    $this->db->statement('CREATE TABLE fixture_reservation_finance (reserved integer NOT NULL)');
    $this->db->table('fixture_reservation_finance')->insert(['reserved' => 0]);
    expect(fn () => $this->db->transaction(function () use ($ticket, $input): void {
        $this->app->make(TransactionCoordinator::class)->run(function ($tx) use ($ticket, $input): void {
            $this->db->table('fixture_reservation_finance')->increment('reserved');
            $this->reservations->reserveLocked($ticket, $input, $tx);
        });
    }))->toThrow(ReservationDeferred::class);
    expect($this->db->table('fixture_reservation_finance')->value('reserved'))->toBe(0)->and($this->table('permits')->count())->toBe(0);
});

it('cancel освобождает каждую lease ровно раз и сохраняет reserve и send debts', function (): void {
    $intent = $this->demandOperation();
    $input = $this->reservationInput($intent);
    $ticket = $this->reservations->enqueue($input, $this->work);
    $permit = $this->reservations->reserve($ticket, $input);
    $before = $this->accounting();
    $this->reservations->cancel($this->tenant, $ticket->operationId, $permit->permitId);
    $once = $this->accounting();
    $this->reservations->cancel($this->tenant, $ticket->operationId, $permit->permitId);
    expect($this->accounting())->toBe($once)->and($this->table('reservations')->where('state', 'released')->count())->toBe(3);
    foreach ($once as $i => $budget) {
        expect($budget['inflight'])->toBe(0)->and($budget['tat'])->toBe($before[$i]['tat'])->and($budget['send_tat'])->toBe($before[$i]['send_tat']);
    }
    expect($this->reservations->reserve($ticket, $input)->state)->toBe(PermitState::CancelledBeforeSend);
});

it('отклоняет reuse с другим cost body или route без дополнительного spend', function (): void {
    $intent = $this->demandOperation();
    $original = $intent['request'];
    $intent['request'] = new WireRequest($original->operationId, $original->physicalAttemptId, $original->targetId, 1, 'POST', $original->url,
        $original->timeouts, $original->deadlineAt, body: WireBody::fromString('original'), idempotency: IdempotencyClass::AmbiguousWrite);
    $input = $this->reservationInput($intent);
    $ticket = $this->reservations->enqueue($input, $this->work);
    $permit = $this->reservations->reserve($ticket, $input);
    $before = $this->accounting();
    expect(fn () => $this->reservations->reserve($ticket, $input, cost: 2))->toThrow(AdmissionRejected::class);
    $old = $intent['request'];
    $intent['request'] = new WireRequest($old->operationId, $old->physicalAttemptId, $old->targetId, $old->policyRevision, 'POST', $old->url,
        $old->timeouts, $old->deadlineAt, body: WireBody::fromString('changed'), idempotency: IdempotencyClass::AmbiguousWrite);
    expect(fn () => $this->reservations->reserve($ticket, $this->reservationInput($intent)))->toThrow(AdmissionRejected::class, 'fingerprint_conflict');
    $intent['request'] = $old;
    $other = selectionAddRoutes($this->db, $this->tenant, $this->catalog['profile'], $this->catalog['target'], 1)[0];
    $this->table('routes')->where('id', $other->value)->update(['egress_key' => 'nat:fixture', 'conservative_pool' => false, 'revision' => 2]);
    $intent['route'] = new RouteSelection($other, RouteKind::Direct, $this->catalog['profile'], null, 1, EgressIdentity::explicit('nat:fixture'));
    expect(fn () => $this->reservations->reserve($ticket, $this->reservationInput($intent)))->toThrow(AdmissionRejected::class, 'fingerprint_conflict');
    $this->rejectedSql(fn () => $this->table('permits')->where('id', $permit->permitId->value)->update(['route_id' => Uuid::random()->value]));
    expect($this->accounting())->toBe($before)->and($this->table('permits')->count())->toBe(1);
});

it('predeclared contributor только сужает limits и вызывается до SQL transaction', function (): void {
    $calls = 0;
    $this->budget('traffic:fixture');
    $this->table('budgets')->where('scope_key', '<>', $this->scopeKey)->update(['max_inflight' => 10]);
    $this->contributor([new BudgetConstraint('traffic:fixture', 100000, 1, 10), new BudgetConstraint($this->scopeKey, 1, 100, 100)], function () use (&$calls): void {
        expect($this->db->transactionLevel())->toBe(0);
        $calls++;
    });
    $intent = $this->demandOperation();
    $input = $this->reservationInput($intent);
    $ticket = $this->reservations->enqueue($input, $this->work);
    $this->reservations->reserve($ticket, $input);
    expect($calls)->toBe(1)->and($this->table('reservations')->count())->toBe(4);
    $next = $this->demandOperation();
    $nextInput = $this->reservationInput($next);
    $nextTicket = $this->reservations->enqueue($nextInput, $this->work);
    expect($this->reservations->reserve($nextTicket, $nextInput))->toBeInstanceOf(DemandWait::class);
});

it('missing extra materialization и cross-tenant scope дают hold без first-reserve UPSERT', function (): void {
    $this->budget('tenant:foreign', new TenantKey('foreign'));
    $this->contributor([new BudgetConstraint('tenant:foreign', 1)]);
    $intent = $this->demandOperation();
    $input = $this->reservationInput($intent);
    $before = $this->accounting();
    expect(fn () => $this->reservations->enqueue($input, $this->work))->toThrow(AdmissionRejected::class, 'required_budget_missing');
    expect($this->table('demands')->count())->toBe(0)->and($this->accounting())->toBe($before);
});

it('integer GCRA сохраняет burst precision и отклоняет overflow config', function (): void {
    $gcra = new Gcra(100, 3);
    expect($gcra->eligibleAt(1500, 1000))->toBe(1300)->and($gcra->advance(1500, 1300))->toBe(1600);
    $large = 9007199254740993;
    expect((new Gcra(1, 1))->advance($large, $large))->toBe($large + 1);
    expect(fn () => new Gcra(PHP_INT_MAX, 3))->toThrow(OverflowException::class);
    expect(fn () => new Gcra(0, 1))->toThrow(InvalidArgumentException::class);
    expect(fn () => (new Gcra(2, 1))->advance(PHP_INT_MAX - 1, PHP_INT_MAX - 1))->toThrow(OverflowException::class);
});

it('overflow и positive infinity последнего budget не оставляют partial accounting', function (string $bad): void {
    $intent = $this->demandOperation();
    $input = $this->reservationInput($intent);
    $ticket = $this->reservations->enqueue($input, $this->work);
    $key = $intent['request']->url->origin->routeScopeKey($this->catalog['route']);
    $this->table('budgets')->where('scope_key', $key)->update($bad === 'overflow'
        ? ['emission_interval_us' => PHP_INT_MAX, 'burst' => 3] : ['tat' => 'infinity']);
    $before = $this->accounting();
    expect(fn () => $this->reservations->reserve($ticket, $input))->toThrow($bad === 'overflow' ? OverflowException::class : AdmissionRejected::class);
    expect($this->accounting())->toBe($before)->and($this->table('permits')->count())->toBe(0);
})->with(['overflow', 'infinity']);

it('advisory availability не меняет состояние и не заменяет live grant check', function (): void {
    $repository = $this->app->make(BudgetRepository::class);
    $before = $this->accounting();
    $now = DatabaseClock::micros(DatabaseClock::now($this->db));
    $availability = $repository->availability($repository->read($this->table('budgets')->get()->all()), $now);
    expect(array_unique(array_values($availability)))->toBe([$now])->and($this->accounting())->toBe($before);
    $intent = $this->demandOperation();
    $input = $this->reservationInput($intent);
    $ticket = $this->reservations->enqueue($input, $this->work);
    $this->table('budgets')->where('scope_key', $this->scopeKey)->update(['inflight' => 1]);
    expect($this->reservations->reserve($ticket, $input))->toBeInstanceOf(DemandWait::class)->and($this->table('permits')->count())->toBe(0);
});

it('изменение выбранного catalog или health после prepare отклоняет reservation', function (string $change): void {
    $intent = $this->demandOperation();
    $input = $this->reservationInput($intent);
    $ticket = $this->reservations->enqueue($input, $this->work);
    $pdo = $this->connectionPdo();
    $sql = match ($change) {
        'profile' => 'UPDATE pxm_route_profiles SET revision = revision + 1',
        'route' => 'UPDATE pxm_routes SET enabled = false, revision = revision + 1',
        'target' => 'UPDATE pxm_targets SET retired_at = clock_timestamp(), revision = revision + 1',
        'health' => "UPDATE pxm_route_health SET state = 'open', state_version = state_version + 1",
    };
    $pdo->exec($sql);
    $before = $this->accounting();
    expect(fn () => $this->reservations->reserve($ticket, $input))->toThrow(AdmissionRejected::class);
    expect($this->table('permits')->count())->toBe(0)->and($this->accounting())->toBe($before);
})->with(['profile', 'route', 'target', 'health']);

it('policy edit инвалидирует подготовленную reservation до budget mutation', function (): void {
    $head = Uuid::random();
    $policies = $this->app->make(PolicyCommands::class);
    $policies->update(new UpdatePolicyCommand($head, $this->tenant, PolicyScope::Tenant, 'acme', '{"schema_version":1,"operations":{}}', $this->actor()));
    $intent = $this->demandOperation();
    $input = $this->reservationInput($intent, ['tenant_db:tenant:acme' => 1]);
    $ticket = $this->reservations->enqueue($input, $this->work);
    $policies->update(new UpdatePolicyCommand($head, $this->tenant, PolicyScope::Tenant, 'acme',
        '{"schema_version":1,"operations":{"retry.max_attempts":{"op":"set","value":2}}}', $this->actor(1)));
    $before = $this->accounting();
    expect(fn () => $this->reservations->reserve($ticket, $input))->toThrow(AdmissionRejected::class, 'policy_revision_changed');
    expect($this->accounting())->toBe($before)->and($this->table('permits')->count())->toBe(0);
});

it('lease refs immutable и cancelled lease нельзя вернуть в reserved', function (): void {
    $intent = $this->demandOperation();
    $input = $this->reservationInput($intent);
    $ticket = $this->reservations->enqueue($input, $this->work);
    $permit = $this->reservations->reserve($ticket, $input);
    $this->rejectedSql(fn () => $this->table('reservations')->where('permit_id', $permit->permitId->value)->update(['cost' => 2]));
    expect(fn () => ReservationSchema::down($this->db))->toThrow(RuntimeException::class, 'history');
    $this->reservations->cancel($this->tenant, $ticket->operationId, $permit->permitId);
    $this->rejectedSql(fn () => $this->table('reservations')->where('permit_id', $permit->permitId->value)->update(['state' => 'reserved']));
});

it('не освобождает lease отменой после durable authorization', function (): void {
    $intent = $this->demandOperation();
    $input = $this->reservationInput($intent);
    $ticket = $this->reservations->enqueue($input, $this->work);
    $permit = $this->reservations->reserve($ticket, $input);
    $this->table('permits')->where('id', $permit->permitId->value)->update(['state' => 'authorized', 'authorized_at' => $this->db->raw('clock_timestamp()'), 'claim_id' => Uuid::random()->value]);
    $before = $this->accounting();
    expect(fn () => $this->reservations->cancel($this->tenant, $ticket->operationId, $permit->permitId))->toThrow(InvalidArgumentException::class);
    expect($this->accounting())->toBe($before)->and($this->table('reservations')->where('state', 'released')->count())->toBe(0);
});

it('supplied owner state не обходит live operation root при composable reserve', function (): void {
    $intent = $this->demandOperation();
    $input = $this->reservationInput($intent);
    $ticket = $this->reservations->enqueue($input, $this->work);
    $stale = new LockedOwnerState($input->operation->owner, $input->operation->purpose, $input->operation->deadlineAt, ['operation' => 1]);
    $this->table('operations')->where('id', $ticket->operationId->value)->update(['status' => 'cancelled', 'revision' => 2]);
    expect(fn () => $this->app->make(TransactionCoordinator::class)->run(fn ($tx) => $this->reservations->reserveLocked($ticket, $input, $tx, $stale)))
        ->toThrow(AdmissionRejected::class, 'unverified_owner_state');
    expect($this->table('permits')->count())->toBe(0)->and($this->table('reservations')->count())->toBe(0);
});
