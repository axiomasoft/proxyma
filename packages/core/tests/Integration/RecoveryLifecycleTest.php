<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Proxyma\Application\Admission\ClaimSend;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Recovery\CompletionCoordinator;
use Proxyma\Application\Recovery\Reconciler;
use Proxyma\Contracts\Execution\LockedCompletionReducer;
use Proxyma\Contracts\Policy\PolicyCommands;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\CompletionFact;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\LateReceiptClaim;
use Proxyma\Domain\Execution\LateReceiptOutcome;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\TransportFailure;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\PolicyScope;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Domain\Policy\UpdatePolicyCommand;
use Proxyma\Infrastructure\Eloquent\ReconcileCursorRecord;
use Proxyma\Tests\Support\CatalogTestCase;
use Proxyma\Tests\Support\ClaimFixtures;
use Proxyma\Tests\Support\DemandFixtures;
use Proxyma\Tests\Support\QuotaFixtures;
use Proxyma\Tests\Support\RecoveryFixtures;
use Proxyma\Tests\Support\ReservationFixtures;

foreach (['CatalogTestCase', 'SelectionFixtures', 'DemandFixtures', 'ReservationFixtures', 'ClaimFixtures', 'QuotaFixtures', 'RecoveryFixtures'] as $file) {
    require_once __DIR__.'/../Support/'.$file.'.php';
}
uses(CatalogTestCase::class, DemandFixtures::class, ReservationFixtures::class, ClaimFixtures::class, QuotaFixtures::class, RecoveryFixtures::class)->group('pg');
beforeEach(fn () => $this->initializeRecoveryFixture());
afterEach(function (): void {
    if (is_dir($this->tempRoot)) {
        foreach (glob($this->tempRoot.'/.pxm-tmp-*.part') as $path) {
            unlink($path);
        }
        if (file_exists($this->tempRoot.'/foreign.txt')) {
            unlink($this->tempRoot.'/foreign.txt');
        }
        rmdir($this->tempRoot);
        rmdir(dirname($this->tempRoot));
    }
});

it('reserved expiry освобождает hour/day и inflight один раз, не отматывая debt', function (): void {
    $this->quotaPrepared();
    $before = $this->accounting();
    $this->moveSqlClock('2030-06-01T10:12:00Z');
    expect($this->reconciler->run($this->tenant)->changed)->toBeGreaterThan(0);
    foreach ($this->accounting() as $i => $row) {
        expect($row['inflight'])->toBe(0)->and($row['tat'])->toBe($before[$i]['tat'])->and($row['send_tat'])->toBe($before[$i]['send_tat']);
    }
    expect($this->table('permits')->value('state'))->toBe('expired_before_send')->and((int) $this->table('quota_windows')->sum('reserved'))->toBe(0)
        ->and($this->table('attempts')->count())->toBe(0)->and($this->reconciler->run($this->tenant)->changed)->toBe(0);
});

it('authorized expiry становится unknown, сохраняя consumed и запрещая повторный claim', function (): void {
    [$permit,$input,$wire] = $this->authorized();
    $before = $this->quotaCounts();
    $this->moveSqlClock('2030-06-01T10:12:00Z');
    $this->reconciler->run($this->tenant);
    expect($this->table('permits')->value('state'))->toBe('unknown_after_authorization')->and($this->table('attempts')->value('status'))->toBe('unknown_after_authorization')
        ->and($this->quotaCounts())->toBe($before)->and((int) $this->table('budgets')->sum('inflight'))->toBe(0);
    expect(fn () => $this->claims->claim($permit, $input, $wire))->toThrow(AdmissionRejected::class);
    expect($this->reconciler->run($this->tenant)->changed)->toBe(0)->and($this->emitted)->toBe(0);
});

it('completion сохраняет receipt и release атомарно; duplicate idempotent, differing hash conflict', function (): void {
    $prepared = $this->authorized();
    $receipt = $this->late($prepared);
    $spent = $this->quotaCounts();
    expect($this->completion->recordLateReceipt($receipt)->outcome)->toBe(LateReceiptOutcome::TransitionedToCompleted);
    expect($this->completion->recordLateReceipt($receipt)->outcome)->toBe(LateReceiptOutcome::IdempotentDuplicate);
    expect(fn () => $this->completion->recordLateReceipt($this->late($prepared, body: 'changed')))->toThrow(AdmissionRejected::class, 'receipt_conflict');
    expect($this->table('attempts')->whereNotNull('receipt_digest')->count())->toBe(1)->and($this->table('observations')->count())->toBe(1)
        ->and($this->quotaCounts())->toBe($spent)->and((int) $this->table('budgets')->sum('inflight'))->toBe(0);
    $this->rejectedSql(fn () => $this->table('attempts')->update(['receipt_digest' => str_repeat('f', 64)]));
    $this->rejectedSql(fn () => $this->table('attempts')->delete());
    $this->rejectedSql(fn () => $this->table('permits')->update(['lease_released_at' => null]));
});

it('completion reads policy and catalogue state under shared writer fences', function (): void {
    $this->app->make(PolicyCommands::class)->update(new UpdatePolicyCommand(
        Uuid::random(), $this->tenant, PolicyScope::Tenant, $this->tenant->value,
        '{"schema_version":1,"operations":{}}', $this->actor(),
    ));
    $prepared = $this->authorized();
    $queries = [];
    $this->db->beforeExecuting(function (string $sql) use (&$queries): void {
        $sql = strtolower($sql);
        if (str_contains($sql, 'for share')) {
            $queries[] = $sql;
        }
    });

    expect($this->completion->recordLateReceipt($this->late($prepared))->receiptPersisted)->toBeTrue();

    $shared = implode("\n", $queries);
    expect($shared)->toContain('pxm_policy_heads')->toContain('pxm_route_profiles')
        ->toContain('pxm_routes')->toContain('pxm_targets');
});

it('late receipt разрешает unknown после release и потери owner, stale epoch не стирает факт', function (): void {
    $prepared = $this->authorized();
    $receipt = $this->late($prepared);
    $this->moveSqlClock('2030-06-01T10:12:00Z');
    $this->reconciler->run($this->tenant);
    $this->table('target_health')->update(['health_epoch' => 2, 'state_version' => 2]);
    $this->table('demands')->update(['state' => 'expired']);
    $result = $this->completion->recordLateReceipt($receipt);
    expect($result->receiptPersisted)->toBeTrue()->and($result->healthReducerAllowed)->toBeFalse()
        ->and($this->table('permits')->value('state'))->toBe('completed')->and((int) $this->table('budgets')->sum('inflight'))->toBe(0)
        ->and((int) $this->table('quota_windows')->sum('spent'))->toBe(2)->and($this->table('observations')->count())->toBe(1);
    expect($this->table('observation_applications')->count())->toBe(3);
    expect($this->table('demands')->value('state'))->toBe('expired');
});

it('failed transport остаётся conservative unknown без retry или возврата quota', function (): void {
    [, $input] = $this->authorized();
    $this->completion->record($input->operation->operationId, $input->request->physicalAttemptId, AttemptResult::failed(TransportFailure::Timeout));
    expect($this->table('attempts')->value('status'))->toBe('unknown_after_authorization')->and($this->table('attempts')->value('receipt_key'))->toBeNull()
        ->and((int) $this->table('quota_windows')->sum('spent'))->toBe(2)->and((int) $this->table('budgets')->sum('inflight'))->toBe(0);
});

it('reducer failure откатывает receipt и release, retry сохраняет один observation', function (): void {
    $prepared = $this->authorized();
    $real = $this->app->make(LockedCompletionReducer::class);
    $this->app->instance(LockedCompletionReducer::class, new class($real) implements LockedCompletionReducer
    {
        public function __construct(private LockedCompletionReducer $real) {}

        public function connection(): Connection
        {
            return $this->real->connection();
        }

        public function apply(CompletionFact $fact, LockedTransactionContext $transaction): void
        {
            $this->real->apply($fact, $transaction);
            throw new RuntimeException('fixture reducer failure');
        }
    });
    $before = $this->accounting();
    expect(fn () => $this->app->make(CompletionCoordinator::class)->recordLateReceipt($this->late($prepared)))->toThrow(RuntimeException::class, 'fixture reducer failure');
    expect($this->table('attempts')->value('receipt_key'))->toBeNull()->and($this->table('observations')->count())->toBe(0)->and($this->accounting())->toBe($before);
    $this->completion->recordLateReceipt($this->late($prepared));
    expect($this->table('observations')->count())->toBe(1);
});

it('bounded pages и durable cursor переживают новый reconciler, idle roots не скрывают due work', function (): void {
    $this->moveSqlClock('2030-06-01T10:30:00Z');
    for ($i = 0; $i < 4; $i++) {
        $this->quotaIntent();
    }
    $this->moveSqlClock('2030-06-01T10:10:00Z');
    for ($i = 0; $i < 3; $i++) {
        $this->quotaPrepared();
    }
    $this->moveSqlClock('2030-06-01T10:12:00Z');
    $page = $this->reconciler->run($this->tenant, 1);
    expect($page->scanned)->toBe(1)->and($page->cursor)->not->toBeNull();
    $restarted = $this->app->make(Reconciler::class);
    expect($restarted->run($this->tenant, 1)->scanned)->toBe(1)->and($restarted->run($this->tenant, 1)->scanned)->toBe(1);
    expect($restarted->run($this->tenant, 1)->scanned)->toBe(0)->and($this->table('permits')->where('state', 'expired_before_send')->count())->toBe(3);
    $table = (new ReconcileCursorRecord)->getTable();
    $this->db->table($table)->update(['lease_token' => Uuid::random()->value, 'lease_until' => '2030-06-01T10:13:00Z']);
    expect($restarted->run($this->tenant)->busy)->toBeTrue();
    $this->moveSqlClock('2030-06-01T10:14:00Z');
    expect($restarted->run($this->tenant)->busy)->toBeFalse();
    $this->moveSqlClock('2030-06-01T10:32:00Z');
    expect($restarted->run($this->tenant)->changed)->toBe(4)
        ->and($this->table('operations')->where('status', 'expired')->count())->toBe(7);
});

it('retention удаляет только безопасные preauthorization rows, сохраняя unknown и operation identity', function (): void {
    $this->quotaPrepared();
    $this->authorized();
    $this->moveSqlClock('2030-07-10T10:12:00Z');
    $this->reconciler->run($this->tenant);
    expect($this->pruner->prune($this->tenant))->toBe(2)->and($this->pruner->prune($this->tenant))->toBe(0)
        ->and($this->table('operations')->count())->toBe(2)->and($this->table('attempts')->count())->toBe(1)
        ->and($this->table('permits')->value('state'))->toBe('unknown_after_authorization');
});

it('owned artifact GC оставляет unknown и чужие файлы, удаляет terminal artifact вне SQL', function (): void {
    [$p,$in] = $this->quotaPrepared();
    $a = $this->artifacts->create($this->tenant, $in->operation->operationId, $p->permitId, 'fixture temp');
    [$unknown,$u] = $this->authorized();
    $b = $this->artifacts->create($this->tenant, $u->operation->operationId, $unknown->permitId, 'unknown temp');
    file_put_contents($this->tempRoot.'/foreign.txt', 'foreign');
    $this->moveSqlClock('2030-07-10T10:12:00Z');
    $this->reconciler->run($this->tenant);
    expect($this->artifacts->prune($this->tenant))->toBe(1)->and($this->artifacts->prune($this->tenant))->toBe(0)
        ->and(file_exists($this->tempRoot.'/.pxm-tmp-'.$a->value.'.part'))->toBeFalse()
        ->and(file_exists($this->tempRoot.'/.pxm-tmp-'.$b->value.'.part'))->toBeTrue()->and(file_get_contents($this->tempRoot.'/foreign.txt'))->toBe('foreign');
    unlink($this->tempRoot.'/foreign.txt');
});

it('cross tenant recovery denied и CLI использует trusted context с bounded limit', function (): void {
    expect(fn () => $this->reconciler->run(new TenantKey('other')))->toThrow(AuthorizationDenied::class);
    $this->artisan('proxyma:reconcile', ['--limit' => 0])->assertExitCode(2);
    $this->artisan('proxyma:reconcile', ['--limit' => 1])->assertExitCode(0);
});

it('receipt rejection не меняет preauthorization, wire mismatch и duplicate digest-only не стирают факт', function (): void {
    [$unused, $input] = $this->quotaPrepared();
    $before = $this->accounting();
    expect(fn () => $this->completion->record($input->operation->operationId, $input->request->physicalAttemptId, $this->delivered()))
        ->toThrow(AdmissionRejected::class, 'authorization_missing');
    expect($this->accounting())->toBe($before);
    $prepared = $this->authorized();
    $receipt = $this->late($prepared);
    $wrong = new LateReceiptClaim($receipt->operationId, $receipt->physicalAttemptId,
        new ContentHash(str_repeat('f', 64)), $receipt->receiptId, $receipt->receiptDigest,
        $receipt->authorizationHealthEpochs, $receipt->verifiedResponse);
    expect($this->completion->recordLateReceipt($wrong)->outcome)->toBe(LateReceiptOutcome::ConflictingReceipt)
        ->and($this->table('attempts')->value('receipt_digest'))->toBeNull();
    $this->completion->recordLateReceipt($receipt);
    $digestOnly = new LateReceiptClaim($receipt->operationId, $receipt->physicalAttemptId, $receipt->wireFingerprint,
        $receipt->receiptId, $receipt->receiptDigest, $receipt->authorizationHealthEpochs);
    expect($this->completion->recordLateReceipt($digestOnly)->outcome)->toBe(LateReceiptOutcome::IdempotentDuplicate);
    expect($this->table('observations')->count())->toBe(1)->and($this->table('observation_applications')->count())->toBe(3);
});

it('local completion идемпотентна и отвергает другой result при том же receipt digest', function (): void {
    [, $input] = $this->authorized();
    $digest = new ContentHash(hash('sha256', 'receipt'));
    $this->completion->record($input->operation->operationId, $input->request->physicalAttemptId, $this->delivered(), $digest);
    $this->completion->record($input->operation->operationId, $input->request->physicalAttemptId, $this->delivered(), $digest);
    expect(fn () => $this->completion->record($input->operation->operationId, $input->request->physicalAttemptId, $this->delivered('changed'), $digest))
        ->toThrow(AdmissionRejected::class, 'receipt_conflict');
    expect($this->table('observations')->count())->toBe(1)->and((int) $this->table('budgets')->sum('inflight'))->toBe(0);
});

it('artifact GC отказывает ownership mismatch и symlink, SQL transaction запрещает file I/O', function (): void {
    [$permit,$input] = $this->quotaPrepared();
    $this->rejectedSql(fn () => $this->table('demand_scopes')->where('demand_id', $permit->ticket->demandId->value)->delete());
    $this->db->beginTransaction();
    try {
        expect(fn () => $this->artifacts->create($this->tenant, $input->operation->operationId, $permit->permitId, 'forbidden'))
            ->toThrow(LogicException::class);
    } finally {
        $this->db->rollBack();
    }
    $artifact = $this->artifacts->create($this->tenant, $input->operation->operationId, $permit->permitId, 'fixture');
    $path = $this->tempRoot.'/.pxm-tmp-'.$artifact->value.'.part';
    file_put_contents($path, 'foreign replacement');
    $this->moveSqlClock('2030-07-10T10:12:00Z');
    $this->reconciler->run($this->tenant);
    expect($this->artifacts->prune($this->tenant))->toBe(0)->and(file_get_contents($path))->toBe('foreign replacement');
    file_put_contents($this->tempRoot.'/foreign.txt', 'foreign');
    unlink($path);
    symlink($this->tempRoot.'/foreign.txt', $path);
    expect($this->artifacts->prune($this->tenant))->toBe(0)->and(is_link($path))->toBeTrue()
        ->and(file_get_contents($this->tempRoot.'/foreign.txt'))->toBe('foreign');
});

it('recovery сохраняет tenant isolation и policy locks для tenant key с двоеточием', function (): void {
    $this->tenant = new TenantKey('acme:eu');
    $this->useTenant($this->tenant);
    $this->app->forgetInstance(ClaimSend::class);
    $this->initializeQuotaFixture();
    $this->originBudget = new Uuid($this->table('budgets')->where('tenant_key', $this->tenant->value)->where('scope_key', $this->scopeKey)->value('id'));
    $this->quota->configure($this->tenant, $this->originBudget, 20, 40, 1);
    $this->app->make(PolicyCommands::class)->update(new UpdatePolicyCommand(
        Uuid::random(), $this->tenant, PolicyScope::Tenant, $this->tenant->value,
        '{"schema_version":1,"operations":{}}', $this->actor()));
    $prepared = $this->authorized();
    $this->moveSqlClock('2030-06-01T10:12:00Z');
    $this->app->make(Reconciler::class)->run($this->tenant);
    $completion = $this->app->make(CompletionCoordinator::class);
    expect($completion->recordLateReceipt($this->late($prepared))->receiptPersisted)->toBeTrue()
        ->and($this->table('permits')->where('tenant_key', 'acme:eu')->value('state'))->toBe('completed')
        ->and((int) $this->table('budgets')->where('tenant_key', 'acme:eu')->sum('inflight'))->toBe(0);
});
