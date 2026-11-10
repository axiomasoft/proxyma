<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Contracts\WorkBudget;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\DemandWait;
use Proxyma\Domain\Admission\QueueLimits;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Catalog\UpsertTargetCommand;
use Proxyma\Domain\Execution\DemandState;
use Proxyma\Domain\Execution\DemandTicket;
use Proxyma\Domain\Execution\Duration;
use Proxyma\Domain\Execution\OperationCorrelation;
use Proxyma\Domain\Execution\RouteSelection;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\DemandQueueSchema;
use Proxyma\Tests\Support\CatalogTestCase;
use Proxyma\Tests\Support\DemandFixtures;
use Proxyma\Tests\Support\FixtureWorkBudget;

require_once __DIR__.'/../Support/CatalogTestCase.php';
require_once __DIR__.'/../Support/DemandFixtures.php';

uses(CatalogTestCase::class, DemandFixtures::class);

beforeEach(function (): void {
    $this->initializeDemandFixture();
});

it('сохраняет generic parent correlation и idempotent operation без продления срока', function (): void {
    $parent = Uuid::random();
    $intent = $this->demandOperation(parent: $parent);
    $op = $intent['operation'];
    $this->operations->create($op, $this->catalog['profile'], $this->catalog['target'], $intent['timeouts'], $parent);
    $row = $this->table('operations')->where('id', $op->operationId->value)->first();
    expect($row->external_operation_key)->toBe($op->externalOperationKey)->and($row->parent_attempt_id)->toBe($parent->value)
        ->and(new DateTimeImmutable($row->deadline))->toEqual($op->deadlineAt)
        ->and((int) $row->revision)->toBe(1)->and($this->table('operations')->count())->toBe(1);
    expect(fn () => $this->operations->create($op, $this->catalog['profile'], $this->catalog['target'], $intent['timeouts'], Uuid::random()))
        ->toThrow(AdmissionRejected::class, 'identity_conflict');
    $tooLong = new OperationCorrelation(Uuid::random(), $op->owner, new DateTimeImmutable('+2 hours'));
    expect(fn () => $this->operations->create($tooLong, $this->catalog['profile'], $this->catalog['target'], $intent['timeouts']))
        ->toThrow(AdmissionRejected::class, 'deadline_out_of_bounds');
});

it('повторяет один immutable ticket и отклоняет другой fingerprint с тем же physical id', function (): void {
    $intent = $this->demandOperation();
    $first = $this->enqueueDemand($intent);
    $again = $this->enqueueDemand($intent);
    expect($again->demandId->value)->toBe($first->demandId->value)->and($again->ticketSequence)->toBe($first->ticketSequence)
        ->and($again->expiresAt)->toEqual($first->expiresAt)->and($this->table('demands')->count())->toBe(1);
    $old = $intent['request'];
    $intent['request'] = new WireRequest($old->operationId, $old->physicalAttemptId, $old->targetId, 1, 'GET', new SafeUrl('https://example.test/different'), $old->timeouts, $old->deadlineAt);
    expect(fn () => $this->enqueueDemand($intent))->toThrow(AdmissionRejected::class, 'fingerprint_conflict');
    expect($this->table('demands')->count())->toBe(1)->and($this->table('demand_scopes')->count())->toBe(1);
});

it('применяет hard operation attempt bound даже после cancellation', function (): void {
    $intent = $this->demandOperation();
    $wire = $intent['request'];
    for ($i = 0; $i < 3; $i++) {
        $intent['request'] = new WireRequest($wire->operationId, Uuid::random(), $wire->targetId, 1, 'GET', $wire->url, $wire->timeouts, $wire->deadlineAt);
        $ticket = $this->enqueueDemand($intent);
        $this->queue->cancel($this->tenant, $ticket->operationId, $ticket->demandId);
    }
    $intent['request'] = new WireRequest($wire->operationId, Uuid::random(), $wire->targetId, 1, 'GET', $wire->url, $wire->timeouts, $wire->deadlineAt);
    expect(fn () => $this->enqueueDemand($intent))->toThrow(AdmissionRejected::class, 'attempt_limit');
    expect($this->table('demands')->count())->toBe(3);
});

it('соблюдает FIFO и cancellation головы на полном наборе scopes', function (): void {
    $first = $this->demandOperation();
    $second = $this->demandOperation();
    $a = $this->enqueueDemand($first);
    $b = $this->enqueueDemand($second);
    expect($a->ticketSequence)->toBeLessThan($b->ticketSequence);
    $wait = $this->takeDemand($b, $second);
    expect($wait)->toBeInstanceOf(DemandWait::class)->and($wait->reason)->toBe('fifo_predecessor');
    $this->queue->cancel($this->tenant, $a->operationId, $a->demandId);
    $this->queue->cancel($this->tenant, $a->operationId, $a->demandId);
    expect($this->takeDemand($b, $second)->state)->toBe(DemandState::Admitted)
        ->and($this->table('demands')->where('id', $a->demandId->value)->value('state'))->toBe('cancelled');
});

it('пропускает истёкшую голову и не возрождает ticket при retry', function (): void {
    $short = $this->queueWithLimits(new QueueLimits(ticketTtlMs: 10));
    $first = $this->demandOperation();
    $a = $this->enqueueDemand($first, queue: $short);
    $long = $this->queueWithLimits(new QueueLimits);
    $second = $this->demandOperation();
    $b = $this->enqueueDemand($second, queue: $long);
    $this->db->select('SELECT pg_sleep(0.025)');
    expect($this->takeDemand($b, $second, queue: $long)->state)->toBe(DemandState::Admitted);
    $expired = $this->enqueueDemand($first, queue: $short);
    expect($expired->state)->toBe(DemandState::Expired)->and($expired->expiresAt)->toEqual($a->expiresAt)
        ->and($expired->demandId->value)->toBe($a->demandId->value);
});

it('ограничивает очередь по каждому scope без частичного enqueue', function (): void {
    $queue = $this->queueWithLimits(new QueueLimits(maxPendingPerScope: 1));
    $first = $this->demandOperation();
    $a = $this->enqueueDemand($first, queue: $queue);
    $second = $this->demandOperation();
    $blocked = $this->enqueueDemand($second, queue: $queue);
    expect($blocked)->toBeInstanceOf(DemandWait::class)->and($blocked->reason)->toBe('queue_full')
        ->and($blocked->retryAt->value)->toEqual($a->expiresAt)->and($this->table('demands')->count())->toBe(1);
    $this->queue->cancel($this->tenant, $a->operationId, $a->demandId);
    expect($this->enqueueDemand($second, queue: $queue))->toBeInstanceOf(DemandTicket::class);
});

it('учитывает все budgets старшего ticket, ограничивает skip и восстанавливает его приоритет', function (): void {
    $extra = $this->budget('tenant:extra', inflight: 1);
    $older = $this->demandOperation();
    $a = $this->enqueueDemand($older, ['tenant:extra']);
    $younger = $this->demandOperation();
    $b = $this->enqueueDemand($younger);
    $strict = $this->queueWithLimits(new QueueLimits(maxEligibleSkip: 0));
    expect($this->takeDemand($b, $younger, queue: $strict)->reason)->toBe('fifo_skip_bound');
    expect($this->takeDemand($b, $younger)->state)->toBe(DemandState::Admitted);
    $this->table('budgets')->where('id', $extra)->update(['inflight' => 0]);
    $third = $this->demandOperation();
    $c = $this->enqueueDemand($third);
    expect($this->takeDemand($c, $third)->reason)->toBe('fifo_predecessor');
    expect($this->takeDemand($a, $older, ['tenant:extra'])->state)->toBe(DemandState::Admitted);
    expect($this->takeDemand($c, $third)->state)->toBe(DemandState::Admitted);
});

it('не навязывает global FIFO независимым origins', function (): void {
    $older = $this->demandOperation();
    $this->enqueueDemand($older);
    $target = Uuid::random();
    $origin = CanonicalOrigin::of('https', 'other.test');
    $this->commands()->upsertTarget(new UpsertTargetCommand($target, $this->tenant, 'other', $origin, 'default', $this->actor()));
    $this->budget($origin->budgetScopeKey());
    $younger = $this->demandOperation($target, 'https://other.test/path');
    $ticket = $this->enqueueDemand($younger);
    expect($this->takeDemand($ticket, $younger)->state)->toBe(DemandState::Admitted);
});

it('не теряет system constraints и не допускает unknown shared egress без materialized budget', function (): void {
    $systemBudget = $this->budget($this->scopeKey, TenantKey::system(), inflight: 1);
    $intent = $this->demandOperation();
    $ticket = $this->enqueueDemand($intent);
    expect($this->table('demand_scopes')->where('demand_id', $ticket->demandId->value)->count())->toBe(2)
        ->and($this->takeDemand($ticket, $intent)->reason)->toBe('budget_wait');
    $this->table('budgets')->where('id', $systemBudget)->update(['inflight' => 0]);
    $pooled = $this->demandOperation();
    $pooled['route'] = new RouteSelection($this->catalog['route'], RouteKind::Direct, $this->catalog['profile'], null, 1, EgressIdentity::conservativePool('shared'));
    expect(fn () => $this->enqueueDemand($pooled))->toThrow(AdmissionRejected::class, 'snapshot_changed');
    $this->table('routes')->where('id', $this->catalog['route']->value)->update(['egress_key' => 'pool:shared', 'conservative_pool' => true, 'revision' => 2]);
    expect(fn () => $this->enqueueDemand($pooled))->toThrow(AdmissionRejected::class, 'required_budget_missing');
    $this->budget($pooled['request']->url->origin->egressScopeKey($pooled['route']->egress));
    expect($this->enqueueDemand($pooled))->toBeInstanceOf(DemandTicket::class);
});

it('не делает enqueue без work slice, missing config и прав tenant', function (): void {
    $intent = $this->demandOperation();
    $this->work = new FixtureWorkBudget(false);
    expect($this->enqueueDemand($intent)->reason)->toBe('slice_exhausted')->and($this->table('demands')->count())->toBe(0);
    $this->work = new FixtureWorkBudget;
    expect(fn () => $this->enqueueDemand($intent, ['tenant:missing']))->toThrow(AdmissionRejected::class, 'required_budget_missing');
    $this->useTenant(new TenantKey('foreign'));
    expect(fn () => $this->enqueueDemand($intent))->toThrow(AuthorizationDenied::class);
    expect($this->table('demands')->count())->toBe(0);
});

it('после ожидания повторно проверяет owner cancellation generation и deadline', function (string $mutation): void {
    $older = $this->demandOperation();
    $this->enqueueDemand($older);
    $intent = $this->demandOperation();
    $ticket = $this->enqueueDemand($intent);
    expect($this->takeDemand($ticket, $intent))->toBeInstanceOf(DemandWait::class);
    $pdo = $this->connectionPdo();
    $change = match ($mutation) {
        'cancel' => "status = 'cancelled'",
        'generation' => 'owner_generation = 2',
        'deadline' => "deadline = clock_timestamp() - interval '1 second'",
    };
    $pdo->prepare('UPDATE pxm_operations SET '.$change.' WHERE id = ?')->execute([$ticket->operationId->value]);
    expect(fn () => $this->takeDemand($ticket, $intent))->toThrow(AdmissionRejected::class, 'owner_lost');
    expect($this->table('demands')->where('id', $ticket->demandId->value)->value('state'))->toBe('active');
})->with(['cancel', 'generation', 'deadline']);

it('запрещает изменение lifetime identity scope set и повторный вход terminal demand', function (): void {
    $intent = $this->demandOperation();
    $ticket = $this->enqueueDemand($intent);
    $query = fn () => $this->table('demands')->where('id', $ticket->demandId->value);
    $this->rejectedSql(fn () => $query()->update(['wire_fingerprint' => str_repeat('f', 64)]));
    $this->rejectedSql(fn () => $query()->update(['expires_at' => $this->db->raw("expires_at + interval '1 second'")]));
    $this->rejectedSql(fn () => $this->table('demand_scopes')->where('demand_id', $ticket->demandId->value)->delete());
    $extra = $this->budget('tenant:later');
    expect(fn () => $this->db->transaction(fn () => $this->table('demand_scopes')->insert(['tenant_key' => $this->tenant->value, 'demand_id' => $ticket->demandId->value,
        'budget_tenant_key' => $this->tenant->value, 'budget_id' => $extra])))->toThrow(PDOException::class, 'sealed scopes');
    $this->queue->cancel($this->tenant, $ticket->operationId, $ticket->demandId);
    $this->rejectedSql(fn () => $query()->update(['state' => 'active']));
    expect(fn () => DemandQueueSchema::down($this->db))->toThrow(RuntimeException::class, 'history');
});

it('снимает project work budget вне SQL при enqueue и повторном take', function (): void {
    $db = $this->db;
    $work = new class($db) implements WorkBudget
    {
        public int $calls = 0;

        public function __construct(private Connection $db) {}

        public function canStart(Duration $required): bool
        {
            expect($this->db->transactionLevel())->toBe(0)->and($required->milliseconds)->toBe(1);
            $this->calls++;

            return true;
        }
    };
    $intent = $this->demandOperation();
    $this->work = $work;
    $ticket = $this->enqueueDemand($intent);
    expect($this->takeDemand($ticket, $intent)->state)->toBe(DemandState::Admitted)->and($work->calls)->toBe(2);
});

it('передаёт cancellation accounting-aware admission после создания permit и не меняет debts', function (): void {
    $intent = $this->demandOperation();
    $ticket = $this->enqueueDemand($intent);
    $debts = $this->table('budgets')->select(['tat', 'send_tat', 'inflight'])->first();
    $this->table('permits')->insert(['tenant_key' => $this->tenant->value, 'operation_id' => $ticket->operationId->value,
        'demand_id' => $ticket->demandId->value, 'route_id' => $this->catalog['route']->value, 'target_id' => $this->catalog['target']->value,
        'physical_attempt_id' => $ticket->physicalAttemptId->value, 'wire_fingerprint' => $intent['request']->fingerprint()->hex,
        'cost' => 1, 'not_before' => $this->db->raw('clock_timestamp()'), 'expires_at' => $this->db->raw("clock_timestamp() + interval '20 seconds'")]);
    expect(fn () => $this->queue->cancel($this->tenant, $ticket->operationId, $ticket->demandId))
        ->toThrow(AdmissionRejected::class, 'accounting_cancellation_required');
    expect($this->table('demands')->where('id', $ticket->demandId->value)->value('state'))->toBe('active')
        ->and($this->table('budgets')->select(['tat', 'send_tat', 'inflight'])->first())->toEqual($debts);
});

it('мигрирует legacy demand без потери sequence или correlation', function (): void {
    DemandQueueSchema::down($this->db);
    $operation = $this->operation($this->catalog);
    $id = Uuid::random();
    $this->table('demands')->insert(['id' => $id->value, 'tenant_key' => $this->tenant->value, 'operation_id' => $operation->value,
        'profile_id' => $this->catalog['profile']->value, 'target_id' => $this->catalog['target']->value, 'purpose' => 'normal',
        'cost' => 1, 'deadline' => $this->db->raw("clock_timestamp() + interval '1 minute'")]);
    $sequence = $this->table('demands')->where('id', $id->value)->value('sequence');
    DemandQueueSchema::up($this->db);
    $row = $this->table('demands')->where('id', $id->value)->first();
    expect($row->operation_id)->toBe($operation->value)->and($row->sequence)->toBe($sequence)
        ->and($row->physical_attempt_id)->toBeNull()->and($row->state)->toBe('active');
});
