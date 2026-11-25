<?php

declare(strict_types=1);

namespace Proxyma\Tests\Support;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Packman\Testkit\Support\Database\SuiteDatabaseConnection;
use Packman\Testkit\Support\Database\TestDatabaseGuard;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/** Test-only processes and real PG barriers; trace never contains bindings or wire material. */
final class ConcurrentWorkers
{
    private array $workers = [];

    private array $events = [];

    private array $cooldowns = [];

    private int $started;

    private string $case = 'unassigned';

    public ?PDO $barrier;

    public ?PDO $observer;

    private bool $finished = false;

    public function __construct(private Connection $db, private string $schema, private string $root)
    {
        if (preg_match('/^pxm_t_[a-f0-9]{16}$/D', $schema) !== 1) {
            throw new RuntimeException('Invalid authority fixture schema.');
        }
        $this->started = hrtime(true);
        $this->barrier = $this->pdo();
        $this->observer = $this->pdo();
        $this->events[] = ['event' => 'harness', 'barrier_pid' => (int) $this->barrier->query('SELECT pg_backend_pid()')->fetchColumn(),
            'observer_pid' => (int) $this->observer->query('SELECT pg_backend_pid()')->fetchColumn(), 'os_pid' => getmypid()];
    }

    public function name(string $case): void
    {
        $this->case = $case;
    }

    private function pdo(): PDO
    {
        $identity = SuiteDatabaseConnection::pgsqlIdentity();
        TestDatabaseGuard::assertDestructiveSafe($identity);
        $pdo = new PDO(
            SuiteDatabaseConnection::pgsqlDsn($identity),
            $identity->username,
            SuiteDatabaseConnection::pgsqlPassword(),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        if (getenv('PROXYMA_DOCKER_RIG') === '1' && $pdo->query('SELECT current_database(),current_user,(SELECT value FROM proxyma_rig.marker)')->fetch(PDO::FETCH_NUM)
            !== ['proxyma_test', 'proxyma_test', 'proxyma_test_v1']) {
            throw new RuntimeException('Authority workers require the isolated marked proxyma_test.');
        }

        $pdo->exec('SET search_path TO '.$this->schema.',pg_catalog');
        $pdo->exec("SET statement_timeout='10s'");

        return $pdo;
    }

    public function worker(array $payload, string $label, string $action): Process
    {
        $payload += ['schema' => $this->schema, 'label' => $label, 'action' => $action];
        $worker = new Process([PHP_BINARY, $this->root.'/packages/core/tests/Support/AuthorityWorker.php', json_encode($payload, JSON_THROW_ON_ERROR)])->setTimeout(20);
        $this->workers[$label] = $worker;

        return $worker;
    }

    public function start(Process $worker, string $label): void
    {
        $this->events[] = ['event' => 'start', 'label' => $label, 'time_ns' => hrtime(true) - $this->started];
        $worker->start();
    }

    public function awaitLock(Process $worker, string $label, string $expectedTable, int $timeoutMs = 8000): void
    {
        $until = hrtime(true) + $timeoutMs * 1_000_000;
        do {
            $q = $this->observer->prepare("SELECT pid,wait_event,left(query,512) AS query FROM pg_stat_activity
                WHERE application_name=? AND wait_event_type='Lock'");
            $q->execute(['proxyma-p43-'.$this->schema.'-'.$label]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                preg_match('/(?:from|update)\s+"?([a-z_]+)"?/i', $row['query'], $table);
                $blockedTable = $table[1] ?? 'advisory';
                if ($blockedTable !== $expectedTable) {
                    usleep(10000);

                    continue;
                }
                $this->events[] = ['event' => 'blocked', 'label' => $label, 'pg_pid' => (int) $row['pid'], 'wait' => $row['wait_event'],
                    'table' => $blockedTable, 'time_ns' => hrtime(true) - $this->started];

                return;
            }
            usleep(10000);
        } while (hrtime(true) < $until && $worker->isRunning());
        throw new RuntimeException('Worker did not reach SQL barrier: '.$label.' '.$expectedTable.'. '.$worker->getErrorOutput());
    }

    public function lockRow(string $table, string $id): void
    {
        if (! in_array($table, ['pxm_route_profiles', 'pxm_endpoints', 'pxm_targets', 'pxm_policy_heads', 'pxm_budgets', 'fixture_control'], true)) {
            throw new RuntimeException('Unknown authority barrier table.');
        }
        $this->barrier->beginTransaction();
        $q = $this->barrier->prepare('SELECT * FROM '.$table.' WHERE id=? FOR UPDATE');
        $q->execute([$id]);
        $q->fetchAll();
        $this->events[] = ['event' => 'barrier-held', 'table' => $table, 'time_ns' => hrtime(true) - $this->started];
    }

    public function release(): void
    {
        if ($this->barrier->inTransaction()) {
            $this->barrier->commit();
        }
        $this->events[] = ['event' => 'barrier-released', 'time_ns' => hrtime(true) - $this->started];
    }

    public function result(Process $worker, string $label): array
    {
        $worker->wait();
        if ($worker->getExitCode() !== 0) {
            $this->events[] = ['event' => 'worker-failed', 'label' => $label, 'exit' => $worker->getExitCode()];
            throw new RuntimeException('Authority worker failed, exit '.$worker->getExitCode().': '.$worker->getErrorOutput());
        }
        $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->events[] = ['event' => 'result', 'label' => $label, 'result' => $result, 'time_ns' => hrtime(true) - $this->started];

        return $result;
    }

    public function rememberCooldowns(): void
    {
        foreach (['route_health', 'route_target_health', 'target_health'] as $table) {
            foreach ($this->db->table('pxm_'.$table)->whereNotNull('open_until')->get() as $row) {
                $this->cooldowns[] = ['table' => 'pxm_'.$table, 'id' => $row->id, 'until' => $row->open_until];
            }
        }
    }

    public function assertInvariants(): void
    {
        $violations = $this->db->selectOne("SELECT
            (SELECT count(*) FROM pxm_budgets b WHERE b.inflight<0 OR b.inflight<>(SELECT count(*) FROM pxm_reservations r
                WHERE r.budget_id=b.id AND r.state IN ('reserved','spent'))) AS inflight,
            (SELECT count(*) FROM pxm_quota_windows w WHERE w.reserved<0 OR w.spent<0
                OR w.reserved<>(SELECT coalesce(sum(q.cost),0) FROM pxm_quota_reservations q WHERE q.window_id=w.id AND q.state='reserved')
                OR w.spent<>(SELECT coalesce(sum(q.cost),0) FROM pxm_quota_reservations q WHERE q.window_id=w.id AND q.state='consumed')) AS quota,
            (SELECT count(*) FROM pxm_permits p WHERE (p.authorized_at IS NOT NULL)::int<>(SELECT count(*) FROM pxm_attempts a WHERE a.permit_id=p.id)
                OR (p.state='completed' AND NOT EXISTS(SELECT 1 FROM pxm_attempts a WHERE a.permit_id=p.id AND a.status='completed' AND a.receipt_key IS NOT NULL))) AS attempts,
            (SELECT count(*) FROM pxm_operations o WHERE o.authorized_attempts<>(SELECT count(*) FROM pxm_attempts a WHERE a.operation_id=o.id)) AS operations,
            (SELECT count(*) FROM pxm_observations v WHERE NOT EXISTS(SELECT 1 FROM pxm_attempts a WHERE a.id=v.attempt_id AND a.receipt_id=v.receipt_id AND a.receipt_key IS NOT NULL)) AS receipts");
        foreach ((array) $violations as $name => $count) {
            \expect((int) $count)->toBe(0, 'aggregate '.$name);
        }
        foreach ($this->cooldowns as $cooldown) {
            $current = $this->db->table($cooldown['table'])->where('id', $cooldown['id'])->value('open_until');
            \expect($current)->not->toBeNull();
            \expect(new DateTimeImmutable($current) >= new DateTimeImmutable($cooldown['until']))->toBeTrue('cooldown continuity');
        }
        \expect((int) $this->db->table('fixture_finance')->min('reserved'))->toBeGreaterThanOrEqual(0);
        $this->events[] = ['event' => 'aggregate-invariants-pass', 'time_ns' => hrtime(true) - $this->started];
    }

    public function finish(): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        try {
            if ($this->barrier->inTransaction()) {
                $this->barrier->rollBack();
            }
            $this->barrier->query('SELECT pg_advisory_unlock_all()');
            foreach ($this->workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(0.1);
                }
            }
            $error = null;
            try {
                $this->assertInvariants();
            } catch (Throwable $failure) {
                $error = $failure;
                $this->events[] = ['event' => 'aggregate-invariants-fail', 'type' => $failure::class];
            }
            file_put_contents($this->root.'/build/p43-matrix-trace.jsonl', json_encode(['case' => $this->case, 'schema' => $this->schema,
                'run_os_pid' => getmypid(), 'duration_ns' => hrtime(true) - $this->started, 'events' => $this->events], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
            if ($error !== null) {
                throw $error;
            }
        } finally {
            // PHPUnit retains test objects; release their sessions without waiting for GC.
            $this->workers = [];
            $this->barrier = null;
            $this->observer = null;
        }
    }
}
