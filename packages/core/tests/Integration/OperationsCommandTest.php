<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Diagnostics\AdmissionDiagnosis;
use Proxyma\Application\Maintenance\MaintenanceSchedule;
use Proxyma\Application\Recovery\Reconciler;
use Proxyma\Contracts\Execution\PhysicalTransport;
use Proxyma\Contracts\Health\DiagnosticsQueries;
use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\AuthorizedWireRequest;
use Proxyma\Domain\Execution\DecodedTextBody;
use Proxyma\Domain\Execution\ResponseDescriptor;
use Proxyma\Domain\Execution\TransportFailure;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\DatabaseClock;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Http\DnsResolver;
use Proxyma\Tests\Support\CatalogTestCase;
use Proxyma\Tests\Support\ClaimFixtures;
use Proxyma\Tests\Support\DemandFixtures;
use Proxyma\Tests\Support\HealthFixtures;
use Proxyma\Tests\Support\QuotaFixtures;
use Proxyma\Tests\Support\RecoveryFixtures;
use Proxyma\Tests\Support\ReservationFixtures;
use Symfony\Component\Process\Process;

foreach (['CatalogTestCase', 'SelectionFixtures', 'DemandFixtures', 'ReservationFixtures', 'ClaimFixtures', 'QuotaFixtures', 'RecoveryFixtures', 'HealthFixtures'] as $file) {
    require_once __DIR__.'/../Support/'.$file.'.php';
}
uses(CatalogTestCase::class, DemandFixtures::class, ReservationFixtures::class, ClaimFixtures::class, QuotaFixtures::class, RecoveryFixtures::class, HealthFixtures::class)->group('pg');
beforeEach(function (): void {
    $this->app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    $this->initializeHealthFixture();
    $this->reconfigureProxyma(['policy.project_defaults' => ['route.allow_direct' => true]]);
});

function operationsCall(object $test, string $command, array $arguments = []): array
{
    $app = (fn () => $this->app)->call($test);
    $kernel = $app->make(Kernel::class);
    $exit = $kernel->call($command, $arguments);

    return [$exit, json_decode(trim($kernel->output()), true, flags: JSON_THROW_ON_ERROR)];
}

function operationsArguments(object $test): array
{
    return (fn (): array => ['profile' => $this->catalog['profile']->value, 'target' => $this->catalog['target']->value])->call($test);
}

function operationsDatabase(object $test): array
{
    $state = [];
    $db = (fn () => $this->db)->call($test);
    foreach (PackageSchema::tables() as $name => $table) {
        $rows = $db->table($table)->get()->map(static fn (object $row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
        sort($rows, SORT_STRING);
        $state[$name] = $rows;
    }

    return $state;
}

function operationsTransport(object $test, bool $fail = false): object
{
    $app = (fn () => $this->app)->call($test);
    $db = (fn () => $this->db)->call($test);
    $scope = (fn () => $this->transactions->scope)->call($test);
    $app->instance(DnsResolver::class, new DnsResolver($scope, static fn (): array => ['93.184.216.34']));
    $transport = new class($db, $fail) implements PhysicalTransport
    {
        public int $sends = 0;

        public function __construct(private Connection $db, private bool $fail) {}

        public function sendOnce(AuthorizedWireRequest $request): AttemptResult
        {
            expect($this->db->transactionLevel())->toBe(0);
            $request->consume();
            $this->sends++;

            return $this->fail ? AttemptResult::failed(TransportFailure::Timeout)
                : AttemptResult::delivered(new ResponseDescriptor(200, [], new DecodedTextBody(''), 0, 0));
        }
    };
    $app->instance(PhysicalTransport::class, $transport);

    return $transport;
}

it('diagnose и explain полностью read-only даже при запрещённой записи PostgreSQL', function (): void {
    $transport = operationsTransport($this);
    $this->quotaPrepared();
    $before = operationsDatabase($this);
    $this->db->statement('SET default_transaction_read_only=on');
    try {
        foreach (['proxyma:diagnose', 'proxyma:explain'] as $command) {
            [$exit, $report] = operationsCall($this, $command, operationsArguments($this));
            expect($exit)->toBe(0)->and($report['advisory'])->toBeTrue()->and($report['revalidate_on_send'])->toBeTrue()
                ->and($report['as_of'])->toContain('2030-06-01')->and($report['policy_revision'])->toBeGreaterThan(0)
                ->and($report['policy_hash'])->toMatch('/^[a-f0-9]{64}$/')->and($report['candidates'])->toHaveCount(1)
                ->and($report['candidates'][0]['health_epochs'])->not->toBeEmpty()->and($report['fields'])->not->toBeEmpty();
        }
        expect(operationsDatabase($this))->toBe($before)->and($transport->sends)->toBe(0);
    } finally {
        $this->db->statement('SET default_transaction_read_only=off');
    }
});

it('advisory показывает budget deadline и изменяется после правки authority без reservation', function (): void {
    $this->table('budgets')->where('id', $this->originBudget->value)->update(['cooldown_until' => '2030-06-01T10:15:00Z']);
    [$exit, $before] = operationsCall($this, 'proxyma:diagnose', operationsArguments($this));
    expect($exit)->toBe(0)->and($before['candidates'][0]['budget_ready'])->toBeFalse()
        ->and($before['candidates'][0]['next_eligible_at'])->toContain('10:15:00');
    $this->table('route_health')->update(['state' => 'open', 'open_until' => '2030-06-01T10:20:00Z', 'health_epoch' => 2, 'state_version' => 2]);
    [, $after] = operationsCall($this, 'proxyma:explain', operationsArguments($this));
    expect($after['candidates'][0]['eligible'])->toBeFalse()->and($after['candidates'][0]['exclusions'])->toContain('route_health_unavailable')
        ->and($after['candidates'][0]['health_epochs']['route'])->toBe(2)->and($this->table('permits')->count())->toBe(0);
});

it('diagnose сохраняет tenant и route permissions без audit side effects', function (): void {
    $this->permissions(fn ($actor, $tenant, $ability): bool => $ability !== 'catalog.profile.select');
    $before = operationsDatabase($this);
    expect(operationsCall($this, 'proxyma:diagnose', operationsArguments($this)))->toBe([1, ['error' => 'permission_denied']]);
    expect(fn () => $this->app->make(AdmissionDiagnosis::class)->explain(new SubjectChain(new SubjectRef(new TenantKey('other'), 'document', 'x')),
        $this->catalog['profile'], $this->catalog['target']))->toThrow(AuthorizationDenied::class);
    expect(operationsDatabase($this))->toBe($before);
});

it('doctor проверяет schema constraints/config/capabilities и не делает I/O', function (): void {
    $transport = operationsTransport($this);
    [$exit, $report] = operationsCall($this, 'proxyma:doctor');
    expect($exit)->toBe(0)->and($report['healthy'])->toBeTrue()->and($report['network_io'])->toBeFalse()
        ->and($report['schema']['databaseEngine'])->toBe('pgsql')->and($report['scheduler_enabled'])->toBeFalse()
        ->and($report['protocols']['http'])->toBeTrue()->and($transport->sends)->toBe(0);
    $this->db->statement('ALTER TABLE '.PackageSchema::tables()['budgets'].' DROP CONSTRAINT pxm_cooldown_finite');
    [$exit, $report] = operationsCall($this, 'proxyma:doctor');
    expect($exit)->toBe(1)->and($report['schema']['missingConstraints'])->toContain('pxm_cooldown_finite');
    $this->reconfigureProxyma(['wire.digest_key' => 'secret-too-short']);
    [, $report] = operationsCall($this, 'proxyma:doctor');
    expect($report['configuration_valid'])->toBeFalse()->and(json_encode($report))->not->toContain('secret-too-short');
    $this->db->statement('ALTER TABLE '.PackageSchema::tables()['audit_events'].' DISABLE TRIGGER pxm_audit_events_immutable');
    expect($this->app->make(DiagnosticsQueries::class)->verifySchemaCapabilities()->missingConstraints)->toContain('pxm_audit_events_immutable');
});

it('schema doctor обнаруживает отсутствующую required table', function (): void {
    $table = PackageSchema::tables()['probe_claims'];
    $this->db->statement('ALTER TABLE '.$table.' RENAME TO fixture_hidden_claims');
    [$exit, $report] = operationsCall($this, 'proxyma:doctor');
    expect($exit)->toBe(1)->and($report['schema']['missingTables'])->toContain($table);
});

it('health reset требует reason/revision/key и permission, replay не повторяет audit', function (): void {
    $id = $this->table('route_health')->value('id');
    $args = ['scope' => 'route_health', 'id' => $id, '--reason' => 'fixture reset', '--expected-revision' => 1, '--idempotency-key' => 'reset-fixture'];
    $before = operationsDatabase($this);
    foreach (['--reason', '--expected-revision', '--idempotency-key'] as $required) {
        $invalid = $args;
        unset($invalid[$required]);
        expect(operationsCall($this, 'proxyma:health-reset', $invalid)[0])->toBe(2);
    }
    $this->permissions(fn ($actor, $tenant, $ability): bool => $ability !== 'health.reset');
    expect(operationsCall($this, 'proxyma:health-reset', $args)[0])->toBe(1)->and(operationsDatabase($this))->toBe($before);
    $this->commands();
    [$exit, $result] = operationsCall($this, 'proxyma:health-reset', $args);
    $count = $this->table('audit_events')->count();
    expect($exit)->toBe(0)->and($result['state'])->toBe('unknown')->and((int) $result['revision'])->toBe(2)
        ->and(operationsCall($this, 'proxyma:health-reset', $args))->toBe([$exit, $result])->and($this->table('audit_events')->count())->toBe($count);
    $args['--idempotency-key'] = 'new-reset';
    expect(operationsCall($this, 'proxyma:health-reset', $args)[0])->toBe(1);
    $args['--idempotency-key'] = 'reset-fixture';
    $args['--reason'] = 'different';
    expect(operationsCall($this, 'proxyma:health-reset', $args)[0])->toBe(1)->and($this->table('audit_events')->count())->toBe($count);
});

it('scheduler по умолчанию пуст и opt-in регистрирует только bounded maintenance', function (): void {
    $schedule = $this->app->make(Schedule::class);
    expect($schedule->events())->toBeEmpty()->and($this->table('attempts')->count())->toBe(0);
    $this->reconfigureProxyma(['scheduler.enabled' => true]);
    $this->reconfigureProxyma(['maintenance.batch_size' => 7]);
    $this->reconfigureProxyma(['maintenance.retention_seconds' => 86400]);
    $this->app->make(MaintenanceSchedule::class)->register($schedule);
    expect($schedule->events())->toHaveCount(2);
    $commands = implode('\n', array_map(fn ($event) => $event->command, $schedule->events()));
    expect($commands)->toContain('proxyma:reconcile', 'proxyma:prune', '7', '86400')->not->toContain('proxyma:probe');
    $this->reconfigureProxyma(['maintenance.batch_size' => 65]);
    expect(fn () => $this->app->make(MaintenanceSchedule::class)->register(new Schedule))->toThrow(InvalidArgumentException::class);
    expect($this->table('operations')->count())->toBe(0);
});

it('CLI batches отвергают 0/65 и reconcile resume не делает повторный release', function (): void {
    for ($i = 0; $i < 3; $i++) {
        $this->quotaPrepared();
    }
    foreach (['proxyma:reconcile', 'proxyma:prune', 'proxyma:metrics'] as $command) {
        foreach ([0, 65, '1.5'] as $bad) {
            expect(operationsCall($this, $command, ['--limit' => $bad])[0])->toBe(2);
        }
    }
    $this->moveSqlClock('2030-07-10T10:12:00Z');
    [$exit, $page] = operationsCall($this, 'proxyma:reconcile', ['--limit' => 1]);
    expect($exit)->toBe(0)->and($page['scanned'])->toBe(1)->and($page['cursor'])->not->toBeNull();
    $this->app->forgetInstance(Reconciler::class);
    expect(operationsCall($this, 'proxyma:reconcile', ['--limit' => 1])[1]['scanned'])->toBe(1);
    expect(operationsCall($this, 'proxyma:reconcile', ['--limit' => 1])[1]['scanned'])->toBe(1);
    expect(operationsCall($this, 'proxyma:reconcile', ['--limit' => 1])[1]['scanned'])->toBe(0)
        ->and((int) $this->table('budgets')->sum('inflight'))->toBe(0)->and((int) $this->table('quota_windows')->sum('reserved'))->toBe(0);
    expect(operationsCall($this, 'proxyma:prune', ['--limit' => 1])[1]['removed'])->toBe(2);
    expect(operationsCall($this, 'proxyma:prune', ['--limit' => 1])[1]['removed'])->toBe(2);
    expect(operationsCall($this, 'proxyma:prune', ['--limit' => 1])[1]['removed'])->toBe(2);
    expect(operationsCall($this, 'proxyma:prune', ['--limit' => 1])[1]['removed'])->toBe(0);
});

it('retention удерживает active quota windows и их released links', function (): void {
    [$permit] = $this->quotaPrepared();
    $windows = $this->table('quota_windows')->count();
    $this->moveSqlClock('2030-06-01T10:12:00Z');
    $this->reconciler->run($this->tenant);
    expect($this->pruner->prune($this->tenant, 1))->toBe(0)->and($this->table('permits')->where('id', $permit->permitId->value)->exists())->toBeTrue()
        ->and($this->table('quota_reservations')->count())->toBe(2);
    $this->moveSqlClock('2030-06-02T00:00:02Z');
    expect($this->pruner->prune($this->tenant, 1))->toBe(2)->and($this->table('quota_windows')->count())->toBe($windows)
        ->and($this->table('operations')->count())->toBe(1);
});

it('retention не трогает active permit, unknown, completed fact, projection history и health hold', function (): void {
    $this->quotaPrepared();
    $unknown = $this->authorized();
    $completed = $this->authorized();
    $this->completion->recordLateReceipt($this->late($completed));
    $this->healthUnknown();
    $this->table('route_health')->update(['state' => 'open', 'reason_code' => 'credential_hold:1', 'open_until' => null]);
    $history = [$this->table('observations')->get()->toJson(), $this->table('observation_applications')->get()->toJson(), $this->table('route_health')->get()->toJson()];
    expect($this->pruner->prune($this->tenant, 1))->toBe(0);
    $this->moveSqlClock('2030-07-10T10:12:00Z');
    $this->reconciler->run($this->tenant);
    expect($this->pruner->prune($this->tenant))->toBe(2)->and($this->table('attempts')->count())->toBe(2)
        ->and($this->table('permits')->count())->toBe(2)->and($this->table('operations')->count())->toBe(3);
    expect([$this->table('observations')->get()->toJson(), $this->table('observation_applications')->get()->toJson(), $this->table('route_health')->get()->toJson()])->toBe($history);
    expect($this->pruner->prune($this->tenant))->toBe(0);
});

it('prune crash откатывает текущий root и restart завершает безопасный chunk', function (): void {
    $this->quotaPrepared();
    $this->moveSqlClock('2030-07-10T10:12:00Z');
    $this->reconciler->run($this->tenant);
    $before = operationsDatabase($this);
    $crash = true;
    $demands = PackageSchema::tables()['demands'];
    $this->db->beforeExecuting(function ($sql) use (&$crash, $demands): void {
        if ($crash && str_starts_with(strtolower($sql), 'delete') && str_contains($sql, $demands)) {
            $crash = false;
            throw new RuntimeException('fixture crash in prune transaction');
        }
    });
    expect(fn () => $this->pruner->prune($this->tenant))->toThrow(RuntimeException::class, 'fixture crash');
    expect(operationsDatabase($this))->toBe($before);
    expect(operationsCall($this, 'proxyma:prune', ['--limit' => 1])[1]['removed'])->toBe(2)
        ->and($this->table('operations')->count())->toBe(1)->and($this->table('demands')->count())->toBe(0);
});

it('reconcile берёт root до budgets и permits в canonical subset', function (): void {
    $this->quotaPrepared();
    $this->moveSqlClock('2030-06-01T10:12:00Z');
    $queries = [];
    $this->db->listen(function ($event) use (&$queries): void {
        if (str_contains(strtolower($event->sql), 'for update')) {
            $queries[] = strtolower($event->sql);
        }
    });
    $this->reconciler->run($this->tenant, 1);
    $index = static function (string $table) use (&$queries): int {
        foreach ($queries as $i => $sql) {
            if (str_contains($sql, $table)) {
                return $i;
            }
        }
        throw new RuntimeException('Expected canonical lock missing: '.$table);
    };
    $root = $index(PackageSchema::tables()['operations']);
    $budget = $index(PackageSchema::tables()['budgets']);
    $permit = $index(PackageSchema::tables()['permits']);
    expect($root)->toBeLessThan($budget)->and($budget)->toBeLessThan($permit)->and($queries[$root])->toContain('skip locked');
});

it('probe CLI exact scopes/purpose проходит обычный admission и списывает probe budget один раз', function (): void {
    $this->healthUnknown();
    $transport = operationsTransport($this);
    $args = operationsArguments($this) + ['route' => $this->catalog['route']->value];
    [$exit, $result] = operationsCall($this, 'proxyma:probe', $args);
    expect($exit)->toBe(0)->and($result['succeeded'])->toBeTrue()->and($transport->sends)->toBe(1)
        ->and($this->table('attempts')->count())->toBe(1)->and($this->table('operations')->value('purpose'))->toBe('probe')
        ->and($this->table('probe_claims')->count())->toBe(1)->and($this->table('quota_reservations')->where('state', 'consumed')->count())->toBe(4);
    $snapshot = json_decode($this->table('attempts')->value('snapshot'), true);
    expect($snapshot['purpose'])->toBe('probe');
    foreach (['route_health', 'route_target_health', 'target_health'] as $scope) {
        expect($this->table($scope)->value('state'))->toBe('closed');
    }
});

it('probe permission, held scope, quota и bounds запрещают bytes и partial spend', function (): void {
    $this->healthUnknown();
    $transport = operationsTransport($this);
    $args = operationsArguments($this) + ['route' => $this->catalog['route']->value];
    $this->permissions(fn ($actor, $tenant, $ability): bool => $ability !== 'health.probe.execute');
    expect(operationsCall($this, 'proxyma:probe', $args)[0])->toBe(1)->and($this->table('operations')->count())->toBe(0);
    $this->commands();
    expect(operationsCall($this, 'proxyma:probe', $args + ['--seconds' => 61])[0])->toBe(2);
    expect(operationsCall($this, 'proxyma:probe', $args + ['--seconds' => 0])[0])->toBe(2);
    $this->table('route_target_health')->update(['state' => 'open', 'open_until' => null]);
    $health = $this->table('route_health')->get()->toJson();
    expect(operationsCall($this, 'proxyma:probe', $args)[0])->toBe(1)->and($this->table('route_health')->get()->toJson())->toBe($health)
        ->and($this->table('probe_claims')->count())->toBe(0);
    $this->healthUnknown();
    $this->quota->configure($this->tenant, $this->probeBudget, 0, 0, 2);
    expect(operationsCall($this, 'proxyma:probe', $args)[0])->toBe(1)->and($transport->sends)->toBe(0)
        ->and($this->table('attempts')->count())->toBe(0)->and((int) $this->table('budgets')->sum('inflight'))->toBe(0)
        ->and((int) $this->table('quota_windows')->sum('spent'))->toBe(0);
});

it('failed probe не повторяет send и оставляет conservative unknown', function (): void {
    $this->healthUnknown();
    $transport = operationsTransport($this, true);
    [$exit] = operationsCall($this, 'proxyma:probe', operationsArguments($this) + ['route' => $this->catalog['route']->value]);
    expect($exit)->toBe(1)->and($transport->sends)->toBe(1)->and($this->table('attempts')->value('status'))->toBe('unknown_after_authorization')
        ->and((int) $this->table('quota_windows')->sum('spent'))->toBe(4);
});

it('expired probe сначала возвращает bounded backoff без выдачи нового scope token', function (): void {
    $this->healthUnknown();
    [, $claim] = $this->healthProbe(1);
    $this->moveSqlClock('2030-06-01T10:10:02Z');
    $transport = operationsTransport($this);
    $args = operationsArguments($this) + ['route' => $this->catalog['route']->value];
    expect(operationsCall($this, 'proxyma:probe', $args)[0])->toBe(1)->and($transport->sends)->toBe(0)
        ->and($this->table('probe_claims')->count())->toBe(1);
    foreach (['route_health', 'route_target_health', 'target_health'] as $scope) {
        $row = $this->table($scope)->first();
        expect($row->state)->toBe('open')->and($row->reason_code)->toBe('probe_expired')->and($row->probe_token)->toBeNull()
            ->and(new DateTimeImmutable($row->open_until)->getTimestamp() - DatabaseClock::now($this->db)->getTimestamp())->toBeGreaterThan(0)->toBeLessThanOrEqual(300);
    }
    expect(operationsCall($this, 'proxyma:probe', $args)[0])->toBe(1)->and($transport->sends)->toBe(0);
});

it('два PostgreSQL reaper процесса освобождают lease ровно раз на canonical root', function (): void {
    $prepared = $this->authorized();
    $spent = $this->quotaCounts();
    $this->moveSqlClock('2030-06-01T10:12:00Z');
    $cursor = PackageSchema::tables()['reconcile_cursors'];
    $this->db->table($cursor)->insert(['tenant_key' => $this->tenant->value]);
    $barrier = $this->connectionPdo();
    $observer = $this->connectionPdo();
    $barrier->beginTransaction();
    $barrier->query('SELECT * FROM '.$cursor.' FOR UPDATE');
    $workers = [];
    try {
        foreach (['one', 'two'] as $label) {
            $worker = new Process([PHP_BINARY, __DIR__.'/../Support/QuotaWorker.php', json_encode([
                'schema' => $this->fixtureSchema, 'label' => $label, 'operation' => $prepared[1]->operation->operationId->value,
                'classes' => [], 'recovery_action' => 'reconcile', 'limit' => 1,
            ], JSON_THROW_ON_ERROR)]);
            $worker->setTimeout(15);
            $worker->start();
            $workers[] = $worker;
        }
        $until = hrtime(true) + 5_000_000_000;
        do {
            $query = $observer->prepare("SELECT pid FROM pg_stat_activity WHERE application_name LIKE ? AND wait_event_type='Lock'");
            $query->execute(['proxyma-p42-'.$this->fixtureSchema.'-%']);
            $pids = $query->fetchAll(PDO::FETCH_COLUMN);
            if (count($pids) === 2) {
                break;
            }
            usleep(10000);
        } while (hrtime(true) < $until);
        expect(array_unique($pids))->toHaveCount(2);
        $barrier->commit();
        $changed = 0;
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->getExitCode())->toBe(0, $worker->getErrorOutput());
            $changed += json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR)['page']['changed'];
        }
        expect($changed)->toBe(3)->and($this->table('attempts')->value('status'))->toBe('unknown_after_authorization')
            ->and($this->quotaCounts())->toBe($spent)->and((int) $this->table('budgets')->sum('inflight'))->toBe(0);
        expect($this->reconciler->run($this->tenant)->changed)->toBe(0);
        $this->completion->recordLateReceipt($this->late($prepared));
        expect((int) $this->table('budgets')->sum('inflight'))->toBe(0)->and($this->quotaCounts())->toBe($spent);
    } finally {
        if ($barrier->inTransaction()) {
            $barrier->rollBack();
        }
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(0.1, 9);
            }
        }
    }
});
