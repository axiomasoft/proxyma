<?php

declare(strict_types=1);

use Proxyma\Application\Health\ProbeCoordinator;
use Proxyma\Contracts\Policy\PolicyCommands;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Execution\DemandPurpose;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\PolicyScope;
use Proxyma\Domain\Policy\UpdatePolicyCommand;
use Proxyma\Tests\Support\AuthorityFixtures;
use Proxyma\Tests\Support\CatalogTestCase;
use Proxyma\Tests\Support\ClaimFixtures;
use Proxyma\Tests\Support\ConcurrentWorkers;
use Proxyma\Tests\Support\DemandFixtures;
use Proxyma\Tests\Support\QuotaFixtures;
use Proxyma\Tests\Support\RecoveryFixtures;
use Proxyma\Tests\Support\ReservationFixtures;

foreach (['CatalogTestCase', 'SelectionFixtures', 'DemandFixtures', 'ReservationFixtures', 'ClaimFixtures', 'QuotaFixtures', 'RecoveryFixtures', 'TransactionFixtures', 'AuthorityFixtures'] as $file) {
    require_once __DIR__.'/../Support/'.$file.'.php';
}
require_once dirname(__DIR__, 4).'/tests/Support/ConcurrentWorkers.php';
uses(CatalogTestCase::class, DemandFixtures::class, ReservationFixtures::class, ClaimFixtures::class, QuotaFixtures::class, RecoveryFixtures::class, AuthorityFixtures::class)->group('pg');
beforeEach(fn () => $this->initializeAuthorityFixture());
afterEach(fn () => $this->workers->finish());

dataset('authority interference pairs', [
    'profile vs reserve' => ['profile', 'reserve', 'profile_membership_changed'],
    'profile vs claim' => ['profile', 'claim', 'profile_membership_changed'],
    'credential vs claim' => ['credential', 'claim', 'endpoint_revision_changed'],
    'credential vs receipt' => ['credential', 'receipt', 'transitioned_to_completed'],
    'retirement vs send' => ['target', 'claim', 'target_revision_changed'],
    'retirement vs late completion' => ['target', 'receipt', 'transitioned_to_completed'],
    'quota limit vs reserve' => ['budget', 'reserve', 'quota_exhausted'],
    'quota limit vs send' => ['budget', 'claim', 'claim_evidence_changed'],
    'quota limit vs reconcile' => ['budget', 'reconcile', 'reconciled'],
    'policy vs reserve' => ['policy', 'reserve', 'policy_revision_changed'],
]);

it('named authority interference сохраняет aggregate invariants в обоих lock orders', function (string $mutation, string $action, string $denied, bool $writerFirst): void {
    $this->workers->name($mutation.':'.$action.':'.($writerFirst ? 'writer_first' : 'grant_first'));
    if ($mutation === 'credential') {
        $this->proxyClaimFixture();
    }
    if ($mutation === 'profile') {
        selectionAddRoutes($this->db, $this->tenant, $this->catalog['profile'], $this->catalog['target'], 1);
    }
    if ($mutation === 'policy') {
        $head = Uuid::random();
        $this->policyHead = $head->value;
        $this->app->make(PolicyCommands::class)->update(new UpdatePolicyCommand($head, $this->tenant, PolicyScope::Tenant, 'acme',
            '{"schema_version":1,"operations":{}}', $this->actor()));
    }
    $prepared = $this->matrixPrepared($action !== 'reserve');
    if (in_array($action, ['receipt', 'reconcile'], true)) {
        $this->claims->claim(...$prepared);
        $this->moveSqlClock('2030-06-01T10:12:00Z');
        if ($action === 'receipt') {
            $this->reconciler->run($this->tenant);
        }
    }
    $payload = $this->matrixPayload($prepared);
    $before = $this->quotaCounts();
    $this->workers->rememberCooldowns();
    $mutator = $this->workers->worker($payload, 'two', $mutation);
    $actor = $this->workers->worker($payload, 'one', $action);
    if ($writerFirst) {
        [$table,$id] = match ($mutation) {
            'profile' => ['pxm_route_profiles', $this->catalog['profile']->value],
            'credential' => ['pxm_endpoints', $payload['endpoint']],
            'target' => ['pxm_targets', $this->catalog['target']->value],
            'policy' => ['pxm_policy_heads', $this->policyHead],
            'budget' => ['pxm_budgets', $this->originBudget->value],
        };
        $this->workers->lockRow($table, $id);
        $this->workers->start($mutator, 'two');
        $this->workers->awaitLock($mutator, 'two', $table);
        $this->workers->start($actor, 'one');
        $this->workers->awaitLock($actor, 'one', match ($mutation) {
            'budget' => 'pxm_budgets', 'policy' => 'advisory', default => 'pxm_route_profiles',
        });
    } else {
        $this->workers->lockRow('pxm_budgets', $this->originBudget->value);
        $this->workers->start($actor, 'one');
        $this->workers->awaitLock($actor, 'one', 'pxm_budgets');
        $this->workers->start($mutator, 'two');
        $this->workers->awaitLock($mutator, 'two', match ($mutation) {
            'budget' => 'pxm_budgets', 'policy' => 'advisory', default => 'pxm_route_profiles',
        });
    }
    $this->workers->release();
    $result = $this->workers->result($actor, 'one');
    $changed = $this->workers->result($mutator, 'two');
    expect($changed['status'])->toBe('changed')->and($result['pg_pid'])->not->toBe($changed['pg_pid']);
    $expected = $writerFirst ? $denied : match ($action) {
        'reserve' => 'reserved','claim' => 'sent','receipt' => 'transitioned_to_completed','reconcile' => 'reconciled'
    };
    expect($result['status'])->toBe($expected)->and($result['bytes'])->toBe($expected === 'sent' ? 1 : 0);
    if (in_array($action, ['receipt', 'reconcile'], true)) {
        expect($this->table('permits')->value('state'))->toBe($action === 'receipt' ? 'completed' : 'unknown_after_authorization')
            ->and((int) $this->table('quota_windows')->sum('spent'))->toBe(2)->and((int) $this->table('budgets')->sum('inflight'))->toBe(0);
    } elseif ($writerFirst) {
        expect($this->table('attempts')->count())->toBe(0)->and($this->table('permits')->where('state', 'completed')->count())->toBe(0);
        if ($action === 'claim') {
            expect($this->table('permits')->value('state'))->toBe('reserved');
        }
    }
    if ($mutation !== 'budget' && $action === 'claim' && $writerFirst) {
        expect($this->quotaCounts())->toBe($before);
    }
})->with('authority interference pairs')->with(['writer first' => true, 'grant first' => false]);

it('посторонний catalog advisory lock не считается profile row barrier', function (): void {
    $this->workers->name('profile:reserve:unrelated_advisory');
    selectionAddRoutes($this->db, $this->tenant, $this->catalog['profile'], $this->catalog['target'], 1);
    $payload = $this->matrixPayload($this->matrixPrepared(false));
    $this->workers->barrier->prepare('SELECT pg_advisory_lock(hashtextextended(?,0))')->execute(['proxyma:catalog:acme']);
    $this->workers->lockRow('pxm_route_profiles', $this->catalog['profile']->value);
    $mutator = $this->workers->worker($payload, 'two', 'profile');
    $this->workers->start($mutator, 'two');
    $this->workers->awaitLock($mutator, 'two', 'advisory');
    expect(fn () => $this->workers->awaitLock($mutator, 'two', 'pxm_route_profiles', 100))
        ->toThrow(RuntimeException::class, 'Worker did not reach SQL barrier: two pxm_route_profiles.');
    $this->workers->barrier->query('SELECT pg_advisory_unlock_all()');
    $this->workers->awaitLock($mutator, 'two', 'pxm_route_profiles');
    $actor = $this->workers->worker($payload, 'one', 'reserve');
    $this->workers->start($actor, 'one');
    $this->workers->awaitLock($actor, 'one', 'pxm_route_profiles');
    $this->workers->release();
    expect($this->workers->result($mutator, 'two')['status'])->toBe('changed')
        ->and($this->workers->result($actor, 'one')['status'])->toBe('profile_membership_changed')
        ->and($this->table('permits')->count())->toBe(0)->and($this->table('attempts')->count())->toBe(0);
});

it('retained harness освобождает оба PG соединения сразу после finish', function (): void {
    $this->workers->name('harness:retained_connections');
    $retained = [];
    $pids = [];
    for ($i = 0; $i < 3; $i++) {
        $retained[] = $harness = new ConcurrentWorkers($this->db, $this->fixtureSchema, dirname(__DIR__, 4));
        $harness->name('harness:retained_connections:'.$i);
        $pids[] = (int) $harness->barrier->query('SELECT pg_backend_pid()')->fetchColumn();
        $pids[] = (int) $harness->observer->query('SELECT pg_backend_pid()')->fetchColumn();
        $harness->finish();
        $harness->finish();
        $until = hrtime(true) + 1_000_000_000;
        do {
            $remaining = $this->db->table('pg_stat_activity')->whereIn('pid', $pids)->count();
            if ($remaining === 0) {
                break;
            }
            usleep(10000);
        } while (hrtime(true) < $until);
        expect($remaining)->toBe(0, 'finished harness sessions must disappear while the objects remain referenced');
    }
    expect(count($retained))->toBe(3);
});

it('worker startup failure отдаёт безопасный тип ошибки вместо пустого exit 255', function (): void {
    $this->workers->name('harness:startup_failure');
    $worker = $this->workers->worker(['schema' => 'invalid'], 'one', 'reserve');
    $this->workers->start($worker, 'one');
    expect(fn () => $this->workers->result($worker, 'one'))->toThrow(RuntimeException::class, 'Authority worker failed, exit 1:');
    $error = json_decode($worker->getErrorOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($error['error_type'])->toBe(RuntimeException::class)->and($error['sqlstate'])->toBeNull()
        ->and(array_keys($error))->toBe(['error_type', 'sqlstate', 'source', 'line'])->and($worker->getOutput())->toBe('');
});

it('project cancel vs joint finance/network claim блокирует prefix и не оставляет partial financial commit', function (bool $cancelFirst): void {
    $this->workers->name('process_cancel:joint_claim:'.($cancelFirst ? 'cancel_first' : 'claim_first'));
    $this->projectPrefix();
    $prepared = $this->matrixPrepared();
    $payload = $this->matrixPayload($prepared);
    $claim = $this->workers->worker($payload, 'one', 'joint_claim');
    $cancel = $this->workers->worker($payload, 'two', 'cancel');
    $this->workers->lockRow($cancelFirst ? 'fixture_control' : 'pxm_budgets', $cancelFirst ? '1' : $this->originBudget->value);
    if ($cancelFirst) {
        $this->workers->start($cancel, 'two');
        $this->workers->awaitLock($cancel, 'two', 'fixture_control');
        $this->workers->start($claim, 'one');
        $this->workers->awaitLock($claim, 'one', 'fixture_control');
    } else {
        $this->workers->start($claim, 'one');
        $this->workers->awaitLock($claim, 'one', 'pxm_budgets');
        $this->workers->start($cancel, 'two');
        $this->workers->awaitLock($cancel, 'two', 'fixture_control');
    }
    $this->workers->release();
    $result = $this->workers->result($claim, 'one');
    $this->workers->result($cancel, 'two');
    expect($result['status'])->toBe($cancelFirst ? 'project_owner_lost' : 'sent')->and($result['bytes'])->toBe($cancelFirst ? 0 : 1)
        ->and((int) $this->db->table('fixture_finance')->sum('reserved'))->toBe($cancelFirst ? 0 : 1)
        ->and($this->table('attempts')->count())->toBe($cancelFirst ? 0 : 1);
    if (! $cancelFirst) {
        expect(array_slice($result['lock_names'], 0, 6))->toBe(['fixture_control', 'fixture_subject', 'fixture_work', 'fixture_attempt', 'fixture_finance', 'pxm_operations']);
    }
})->with([true, false]);

it('missing prerequisite или cooldown оставляет attempt held и откатывает finance', function (bool $missing): void {
    $this->workers->name('prerequisite:'.($missing ? 'missing_health' : 'cooldown'));
    $this->projectPrefix();
    $prepared = $this->matrixPrepared();
    if ($missing) {
        $this->table('target_health')->delete();
    } else {
        $this->table('target_health')->update(['state' => 'open', 'open_until' => '2030-06-01T11:00:00Z', 'health_epoch' => 2, 'state_version' => 2]);
    }
    $this->workers->rememberCooldowns();
    $before = $this->quotaCounts();
    $payload = $this->matrixPayload($prepared);
    if (! $missing) {
        $this->workers->lockRow('pxm_budgets', $this->originBudget->value);
        $mutator = $this->workers->worker($payload + ['hour' => 20, 'day' => 40], 'two', 'budget');
        $this->workers->start($mutator, 'two');
        $this->workers->awaitLock($mutator, 'two', 'pxm_budgets');
    }
    $worker = $this->workers->worker($payload, 'one', 'joint_claim');
    $this->workers->start($worker, 'one');
    $result = $this->workers->result($worker, 'one');
    if (! $missing) {
        $this->workers->release();
        $updated = $this->workers->result($mutator, 'two');
        expect($updated['status'])->toBe('changed')->and($updated['pg_pid'])->not->toBe($result['pg_pid']);
        expect(fn () => $this->matrixPrepared())->toThrow(AdmissionRejected::class, 'health_unavailable');
    }
    expect($result['status'])->toBe($missing ? 'target_health_missing' : 'health_unavailable')->and($result['bytes'])->toBe(0)
        ->and($this->table('permits')->value('state'))->toBe('reserved')->and($this->table('attempts')->count())->toBe(0)
        ->and((int) $this->db->table('fixture_finance')->sum('reserved'))->toBe(0)->and($this->quotaCounts())->toBe($before);
})->with([true, false]);

it('probe claim vs normal send сохраняет общий origin accounting и probe owner fence', function (): void {
    $this->workers->name('probe_claim:normal_send');
    $normal = $this->matrixPrepared();
    $this->budget(CanonicalOrigin::of('https', 'example.test')->probeScopeKey());
    $intent = $this->quotaIntent(purpose: DemandPurpose::Probe);
    $this->table('route_health')->update(['state' => 'unknown']);
    $claim = $this->app->make(ProbeCoordinator::class)->claim($this->reservationInput($intent));
    expect($claim->token->value)->toBe($intent['operation']->probeToken->value);
    $probe = $this->matrixPrepared(intent: $intent);
    $one = $this->workers->worker($this->matrixPayload($probe), 'one', 'claim');
    $two = $this->workers->worker($this->matrixPayload($normal), 'two', 'claim');
    $this->workers->lockRow('pxm_budgets', $this->originBudget->value);
    $this->workers->start($one, 'one');
    $this->workers->awaitLock($one, 'one', 'pxm_budgets');
    $this->workers->start($two, 'two');
    $this->workers->awaitLock($two, 'two', 'pxm_route_health');
    $this->workers->release();
    expect($this->workers->result($one, 'one')['status'])->toBe('sent')
        ->and($this->workers->result($two, 'two')['status'])->toBe('health_unavailable')
        ->and($this->table('attempts')->count())->toBe(1)->and((int) $this->table('quota_windows')->sum('spent'))->toBe(2)
        ->and($this->table('permits')->where('id', $normal[0]->permitId->value)->value('state'))->toBe('reserved');
});

it('реальный PG deadlock во внешнем prefix имеет bounded retry и один financial commit на worker', function (): void {
    $this->workers->name('external_prefix:real_pg_deadlock');
    $prepared = $this->matrixPrepared(false);
    $payload = $this->matrixPayload($prepared);
    foreach (['one', 'two'] as $label) {
        $this->workers->barrier->prepare('SELECT pg_advisory_lock(hashtextextended(?,0))')->execute(['fixture:p43:deadlock:'.$label]);
    }
    $one = $this->workers->worker($payload, 'one', 'deadlock');
    $two = $this->workers->worker($payload, 'two', 'deadlock');
    $this->workers->start($one, 'one');
    $this->workers->awaitLock($one, 'one', 'advisory');
    $this->workers->start($two, 'two');
    $this->workers->awaitLock($two, 'two', 'advisory');
    $this->workers->barrier->query('SELECT pg_advisory_unlock_all()');
    $a = $this->workers->result($one, 'one');
    $b = $this->workers->result($two, 'two');
    expect($a['status'])->toBe('retried')->and($b['status'])->toBe('retried')->and($a['attempts'] + $b['attempts'])->toBe(3)
        ->and(max($a['attempts'], $b['attempts']))->toBeLessThanOrEqual(3)->and((int) $this->db->table('fixture_finance')->sum('reserved'))->toBe(2)
        ->and($this->table('attempts')->count())->toBe(0);
});

it('retry после authorization до commit не повторяет durable charge, exhaustion оставляет held permit', function (int $faults, string $state, string $status, int $attempts, string $mode = 'query'): void {
    $this->workers->name('claim_before_commit:retry:'.$mode.':'.$state.':'.$faults);
    $this->projectPrefix();
    $prepared = $this->matrixPrepared();
    $payload = $this->matrixPayload($prepared) + ['fault_attempts' => $faults, 'fault_state' => $state, 'fault_mode' => $mode];
    $worker = $this->workers->worker($payload, 'one', 'joint_claim');
    $this->workers->start($worker, 'one');
    $result = $this->workers->result($worker, 'one');
    $sent = $status === 'sent';
    expect($result['attempts'])->toBe($attempts)->and($result['status'])->toBe($status)
        ->and($result['bytes'])->toBe($sent ? 1 : 0)->and($this->table('attempts')->count())->toBe($sent ? 1 : 0)
        ->and((int) $this->db->table('fixture_finance')->sum('reserved'))->toBe($sent ? 1 : 0)
        ->and($this->table('permits')->value('state'))->toBe($sent ? 'authorized' : 'reserved');
})->with([
    'deadlock then success' => [1, '40P01', 'sent', 2],
    'deadlock exhaustion' => [2, '40P01', 'deadlock_exhausted', 2],
    'serialization then success' => [1, '40001', 'sent', 2],
    'constraint violation is not retryable' => [1, '23514', 'prerequisite_failed', 1],
    'commit serialization then success' => [1, '40001', 'sent', 2, 'commit'],
    'commit constraint is not retryable' => [1, '23514', 'prerequisite_failed', 1, 'commit'],
]);

it('afterCommit SQLSTATE не повторяет committed authorization и оставляет conservative unknown', function (): void {
    $this->workers->name('after_commit_fault:never_retry');
    $this->projectPrefix();
    $prepared = $this->matrixPrepared();
    $payload = $this->matrixPayload($prepared) + ['fault_attempts' => 1, 'fault_state' => '40P01', 'fault_mode' => 'after_commit'];
    $worker = $this->workers->worker($payload, 'one', 'joint_claim');
    $this->workers->start($worker, 'one');
    $result = $this->workers->result($worker, 'one');
    expect($result['status'])->toBe('post_commit_failure')->and($result['attempts'])->toBe(1)->and($result['bytes'])->toBe(0)
        ->and($this->table('attempts')->count())->toBe(1)->and((int) $this->db->table('fixture_finance')->sum('reserved'))->toBe(1)
        ->and((int) $this->table('quota_windows')->sum('spent'))->toBe(2)->and($this->table('permits')->value('state'))->toBe('authorized');
    $this->moveSqlClock('2030-06-01T10:12:00Z');
    $this->reconciler->run($this->tenant);
    expect($this->table('permits')->value('state'))->toBe('unknown_after_authorization')->and((int) $this->table('quota_windows')->sum('spent'))->toBe(2)
        ->and((int) $this->table('budgets')->sum('inflight'))->toBe(0);
});
