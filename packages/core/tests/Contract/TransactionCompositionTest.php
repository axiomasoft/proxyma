<?php

declare(strict_types=1);

use Illuminate\Database\PostgresConnection;
use Proxyma\Application\Admission\AuthorityLocker;
use Proxyma\Application\Admission\TransactionCoordinator;
use Proxyma\Contracts\ClaimActivationAuthority;
use Proxyma\Contracts\OwnerAuthority;
use Proxyma\Contracts\TransactionCoordinator as CoordinatorContract;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\AuthorityLockTier;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Execution\AdmissionLockTier;
use Proxyma\Domain\Execution\AuthorizedWireRequest;
use Proxyma\Domain\Execution\AuthorizedWireSnapshot;
use Proxyma\Domain\Execution\ClaimDenialReason;
use Proxyma\Domain\Execution\ClaimDenied;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\OwnerRef;
use Proxyma\Domain\Execution\ResolvedUrl;
use Proxyma\Domain\Execution\SealedWire;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\HealthEpochMap;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Admission\DatabaseOwnerAuthority;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Tests\Support\CatalogTestCase;
use Proxyma\Tests\Support\FinancialLedger;
use Proxyma\Tests\Support\NamedLockTrace;
use Proxyma\Tests\Support\ProjectAdapterGuard;
use Proxyma\Tests\Support\ProjectOwnerAuthority;

require_once __DIR__.'/../Support/CatalogTestCase.php';
require_once __DIR__.'/../Support/SelectionFixtures.php';
require_once __DIR__.'/../Support/TransactionFixtures.php';

uses(CatalogTestCase::class);

// Test-only primitive harness: production activate additionally requires a durable sealed CAS.
function compositionActivate($coordinator, $candidate, $wire, $snapshot, ?int $now = null)
{
    $coordinator->scope->assertOutsideTransaction();
    $candidates = (new ReflectionProperty($coordinator, 'candidates'))->getValue($coordinator);
    $latch = $candidates[$candidate] ?? throw new LogicException('Unknown candidate');
    $authority = new class($coordinator, $now) implements ClaimActivationAuthority
    {
        public function __construct(private $coordinator, private ?int $now) {}

        public function assertActivation($candidate, $snapshot): void
        {
            $this->assertOutsideTransaction();
        }

        public function assertOutsideTransaction(): void
        {
            $this->coordinator->scope->assertOutsideTransaction();
        }

        public function nowMonotonicNs(): int
        {
            return $this->now ?? hrtime(true);
        }

        public function lateRefusal($candidate): void {}

        public function preparedWire($candidate): ?SealedWire
        {
            return null;
        }
    };
    (new ReflectionProperty($candidate, 'activationAuthority'))->setValue($candidate, $authority);

    return AuthorizedWireRequest::activate($candidate, $latch, 0, $wire, $snapshot, $now);
}

beforeEach(function (): void {
    $this->catalog = $this->provision();
    $this->operationId = $this->operation($this->catalog);
    foreach (['control', 'subject', 'work', 'attempt'] as $name) {
        $this->db->statement("CREATE TABLE fixture_{$name} (id integer PRIMARY KEY, enabled boolean NOT NULL DEFAULT true, revision bigint NOT NULL DEFAULT 1,
            generation bigint NOT NULL DEFAULT 1, owner_token text NOT NULL DEFAULT 'token', deadline timestamptz NOT NULL DEFAULT (clock_timestamp() + interval '1 hour'))");
        $this->db->table('fixture_'.$name)->insert(['id' => 1]);
    }
    foreach (['finance', 'network', 'adapter', 'receipts'] as $name) {
        $this->db->statement("CREATE TABLE fixture_{$name} (id integer PRIMARY KEY, reserved integer NOT NULL DEFAULT 0)");
        $this->db->table('fixture_'.$name)->insert(['id' => 1]);
    }
    $this->scope = $this->app->make(ConnectionScope::class);
    $this->transactions = $this->app->make(TransactionCoordinator::class);
    $this->locks = $this->app->make(AuthorityLocker::class);
    $this->owners = new ProjectOwnerAuthority($this->scope);
    $this->owner = new OwnerRef($this->tenant, 'fixture', 'owner', 'token');
    $this->ownerSnapshot = $this->owners->snapshot($this->owner);
    $this->finance = new FinancialLedger($this->db, $this->locks);
    $this->wire = selectionRequest($this->catalog['target']);
    $this->permitId = Uuid::random();
    $this->wireSnapshot = new AuthorizedWireSnapshot($this->permitId, $this->catalog['route'], RouteKind::Direct, null, 1,
        new ResolvedUrl($this->wire->url, '192.0.2.1'), EgressIdentity::explicit('nat:fixture'), $this->wire->url->origin,
        'default', new HealthEpochMap(['route' => 1]), $this->owner, $this->wire->fingerprint(), hrtime(true) + 60_000_000_000);
    $this->candidate = function (LockedTransactionContext $tx) {
        $this->db->table('fixture_network')->where('id', 1)->increment('reserved');
        $clock = $this->db->selectOne("SELECT clock_timestamp() AS authorized, clock_timestamp() + interval '1 minute' AS expires");

        return $this->transactions->issueCandidate($this->permitId, $this->wire->operationId, $this->wire->physicalAttemptId,
            $this->wire->fingerprint(), new DateTimeImmutable($clock->authorized), new DateTimeImmutable($clock->expires), $tx);
    };
});

it('исполняет standalone и caller-owned пути на одной Connection без самостоятельного commit Locked API', function (): void {
    expect($this->app->make(CoordinatorContract::class))->toBe($this->transactions);
    expect($this->app->make(OwnerAuthority::class))->toBeInstanceOf(DatabaseOwnerAuthority::class);
    $candidate = $this->transactions->run(fn ($tx) => ($this->candidate)($tx));
    expect(compositionActivate($this->transactions, $candidate, $this->wire, $this->wireSnapshot)->isConsumed())->toBeFalse();
    expect(fn () => compositionActivate($this->transactions, $candidate, $this->wire, $this->wireSnapshot))->toThrow(LogicException::class);
    $this->db->beginTransaction();
    $second = $this->transactions->locked($this->db, fn ($tx) => ($this->candidate)($tx));
    expect($this->db->transactionLevel())->toBe(1);
    expect(fn () => compositionActivate($this->transactions, $second, $this->wire, $this->wireSnapshot))->toThrow(LogicException::class);
    $this->db->commit();
    expect(compositionActivate($this->transactions, $second, $this->wire, $this->wireSnapshot)->isConsumed())->toBeFalse();
});

it('отклоняет неверную Connection даже с тем же именем и forged или stale context', function (): void {
    $config = $this->db->getConfig();
    $other = new PostgresConnection($this->connectionPdo(), 'proxyma_test', '', $config);
    $other->setTransactionManager($this->app['db.transactions']);
    $this->db->transaction(function () use ($other): void {
        expect(fn () => $this->transactions->locked($other, fn () => true))->toThrow(LogicException::class, 'same Connection');
        $this->db->beginTransaction();
        expect(fn () => $this->transactions->locked($other, fn () => true))->toThrow(LogicException::class, 'same Connection');
        $this->db->rollBack();
        $this->transactions->run(function ($tx): void {
            $forged = new LockedTransactionContext($tx->connectionName, $tx->outerTransactionToken, $tx->monotonicSampledBeforeTxNs);
            expect(fn () => ($this->candidate)($forged))->toThrow(LogicException::class, 'Foreign');
        });
    });
    expect(fn () => $this->transactions->locked($this->db, fn () => true))->toThrow(LogicException::class, 'active PostgreSQL');
});

it('снимает monotonic sample до caller-owned outer transaction', function (): void {
    $this->db->beginTransaction();
    $afterBegin = hrtime(true);
    $candidate = $this->transactions->locked($this->db, fn ($tx) => ($this->candidate)($tx));
    expect($candidate->monotonicSampledBeforeTxNs)->toBeLessThanOrEqual($afterBegin);
    $this->db->rollBack();
});

it('после savepoint release разрешает активацию только после outer commit', function (): void {
    $this->db->beginTransaction();
    $candidate = $this->db->transaction(fn () => $this->transactions->run(fn ($tx) => ($this->candidate)($tx)));
    expect(fn () => compositionActivate($this->transactions, $candidate, $this->wire, $this->wireSnapshot))->toThrow(LogicException::class);
    $this->db->commit();
    expect(compositionActivate($this->transactions, $candidate, $this->wire, $this->wireSnapshot)->isConsumed())->toBeFalse();
});

it('отклоняет expiry и потерю локального latch после замены coordinator', function (): void {
    $candidate = $this->transactions->run(fn ($tx) => ($this->candidate)($tx));
    $newProcess = new TransactionCoordinator($this->scope);
    expect(fn () => $newProcess->activate($candidate, $this->wire, $this->wireSnapshot))->toThrow(LogicException::class, 'Unknown candidate');
    expect(fn () => compositionActivate($this->transactions, $candidate, $this->wire, $this->wireSnapshot, PHP_INT_MAX))->toThrow(LogicException::class, 'expired');
    expect(fn () => compositionActivate($this->transactions, $candidate, $this->wire, $this->wireSnapshot))->toThrow(LogicException::class, 'already been activated');
});

it('откатывает finance и network при первом blocker включая false и typed denial', function (string $mode): void {
    $leaked = null;
    expect(function () use ($mode, &$leaked): void {
        $this->db->transaction(function () use ($mode, &$leaked) {
            return $this->transactions->locked($this->db, function ($tx) use ($mode, &$leaked) {
                $this->locks->owner($this->owners, $this->owner, $this->ownerSnapshot, $tx);
                $this->finance->reserve($tx);
                $this->locks->operation($this->operationId, $this->owner, $tx);
                $leaked = ($this->candidate)($tx);
                if ($mode === 'exception') {
                    throw new AdmissionRejected('network_blocked');
                }

                return $mode === 'false' ? false : new ClaimDenied(ClaimDenialReason::GuardDenied);
            });
        });
    })->toThrow(AdmissionRejected::class);
    expect($this->db->table('fixture_finance')->value('reserved'))->toBe(0)
        ->and($this->db->table('fixture_network')->value('reserved'))->toBe(0);
    expect(fn () => compositionActivate($this->transactions, $leaked, $this->wire, $this->wireSnapshot))->toThrow(LogicException::class);
})->with(['exception', 'false', 'denial']);

it('не активирует savepoint release и инвалидирует candidate при outer rollback', function (): void {
    $this->db->beginTransaction();
    $candidate = $this->db->transaction(fn () => $this->transactions->run(fn ($tx) => ($this->candidate)($tx)));
    expect($this->db->transactionLevel())->toBe(1);
    expect(fn () => compositionActivate($this->transactions, $candidate, $this->wire, $this->wireSnapshot))->toThrow(LogicException::class);
    $this->db->rollBack();
    expect(fn () => compositionActivate($this->transactions, $candidate, $this->wire, $this->wireSnapshot))->toThrow(LogicException::class);
    expect($this->db->table('fixture_network')->value('reserved'))->toBe(0);
});

it('сохраняет несколько candidates одного outer commit и отвергает rolled back savepoint', function (): void {
    $this->db->beginTransaction();
    $this->db->beginTransaction();
    $bad = $this->transactions->run(fn ($tx) => ($this->candidate)($tx));
    $this->db->rollBack();
    $good = $this->transactions->run(fn ($tx) => ($this->candidate)($tx));
    $another = $this->transactions->run(fn ($tx) => ($this->candidate)($tx));
    $this->db->commit();
    expect(fn () => compositionActivate($this->transactions, $bad, $this->wire, $this->wireSnapshot))->toThrow(LogicException::class);
    expect(compositionActivate($this->transactions, $good, $this->wire, $this->wireSnapshot)->isConsumed())->toBeFalse()
        ->and(compositionActivate($this->transactions, $another, $this->wire, $this->wireSnapshot)->isConsumed())->toBeFalse();
});

it('инвалидирует candidate при настоящем deferred constraint commit failure', function (): void {
    $candidate = null;
    expect(function () use (&$candidate): void {
        $this->transactions->run(function ($tx) use (&$candidate): void {
            $candidate = ($this->candidate)($tx);
            $this->table('policy_heads')->insert(['tenant_key' => $this->tenant->value, 'scope_key' => 'fixture', 'current_revision_id' => Uuid::random()->value]);
        }, maxAttempts: 1);
    })->toThrow(PDOException::class);
    expect(fn () => compositionActivate($this->transactions, $candidate, $this->wire, $this->wireSnapshot))->toThrow(LogicException::class);
    expect($this->db->table('fixture_network')->value('reserved'))->toBe(0);
});

it('проверяет live generation, cancellation, deadline и snapshot versions после внешнего изменения', function (string $change): void {
    $pdo = $this->connectionPdo();
    $sql = match ($change) {
        'generation' => 'UPDATE fixture_work SET generation = 2',
        'cancel' => 'UPDATE fixture_control SET enabled = false',
        'deadline' => "UPDATE fixture_work SET deadline = clock_timestamp() - interval '1 second'",
        'subject' => 'UPDATE fixture_subject SET revision = 2',
        'control' => 'UPDATE fixture_control SET revision = 2',
        'operation' => "UPDATE pxm_operations SET status = 'cancelled'",
    };
    $pdo->exec($sql);
    expect(fn () => $this->transactions->run(function ($tx): void {
        $this->locks->owner($this->owners, $this->owner, $this->ownerSnapshot, $tx);
        $this->finance->reserve($tx);
        $this->locks->operation($this->operationId, $this->owner, $tx);
    }))->toThrow(AdmissionRejected::class);
    expect($this->db->table('fixture_finance')->value('reserved'))->toBe(0);
})->with(['generation', 'cancel', 'deadline', 'subject', 'control', 'operation']);

it('соблюдает owner finance network adapter budget порядок и запрещает обратный tier', function (): void {
    $trace = new NamedLockTrace($this->db);
    $this->table('budgets')->insert(['tenant_key' => $this->tenant->value, 'scope_key' => 'origin:fixture', 'emission_interval_us' => 1, 'burst' => 1, 'max_inflight' => 1]);
    $this->transactions->run(function ($tx): void {
        $state = $this->locks->owner($this->owners, $this->owner, $this->ownerSnapshot, $tx);
        $this->finance->reserve($tx);
        $this->locks->operation($this->operationId, $this->owner, $tx);
        $this->locks->rows(AuthorityLockTier::Profile, $this->tenant, [$this->catalog['profile']->value], $tx);
        $this->locks->rows(AuthorityLockTier::Route, $this->tenant, [$this->catalog['route']->value], $tx);
        $this->locks->rows(AuthorityLockTier::Target, $this->tenant, [$this->catalog['target']->value], $tx);
        $this->locks->guard('project', new ProjectAdapterGuard($this->db), $this->wire, $state, $tx);
        $this->locks->rows(AuthorityLockTier::Budget, $this->tenant, ['origin:fixture'], $tx);
        expect(fn () => $this->locks->rows(AuthorityLockTier::Route, $this->tenant, [$this->catalog['route']->value], $tx))->toThrow(LogicException::class, 'advance');
    });
    expect($trace->names)->toBe(['fixture_control', 'fixture_subject', 'fixture_work', 'fixture_attempt', 'fixture_finance',
        'pxm_operations', 'pxm_route_profiles', 'pxm_routes', 'pxm_targets', 'fixture_adapter', 'pxm_budgets']);
});

it('batch policy heads разделяет read lock, сохраняет writer fence и отказывает для отсутствующего состояния', function (): void {
    $this->table('policy_heads')->insert([
        ['tenant_key' => $this->tenant->value, 'scope_key' => 'alpha'],
        ['tenant_key' => TenantKey::SYSTEM, 'scope_key' => 'beta'],
        ['tenant_key' => $this->tenant->value, 'scope_key' => 'omega'],
    ]);
    $queries = 0;
    $this->db->beforeExecuting(function (string $sql) use (&$queries): void {
        if (str_contains($sql, 'pxm_policy_heads') && str_contains(strtolower($sql), 'for share')) {
            $queries++;
        }
    });
    $locked = $this->transactions->run(function ($tx): array {
        $locked = $this->locks->policyRows([
            ['tenant' => $this->tenant, 'scopeKey' => 'omega'],
            ['tenant' => new TenantKey(TenantKey::SYSTEM), 'scopeKey' => 'beta'],
            ['tenant' => $this->tenant, 'scopeKey' => 'alpha'],
        ], $tx);
        $reader = $this->connectionPdo();
        $reader->beginTransaction();
        try {
            $statement = $reader->prepare("SELECT id FROM pxm_policy_heads WHERE tenant_key = 'acme' AND scope_key = 'alpha' FOR SHARE NOWAIT");
            $statement->execute();
        } finally {
            $reader->rollBack();
        }
        $writer = $this->connectionPdo();
        $writer->beginTransaction();
        try {
            expect(function () use ($writer): void {
                $statement = $writer->prepare("SELECT id FROM pxm_policy_heads WHERE tenant_key = 'acme' AND scope_key = 'alpha' FOR UPDATE NOWAIT");
                $statement->execute();
            })->toThrow(PDOException::class, '55P03');
        } finally {
            $writer->rollBack();
        }

        return $locked;
    });
    expect($queries)->toBe(1)
        ->and(array_keys($locked))->toBe(['alpha'."\0".'acme', 'beta'."\0".TenantKey::SYSTEM, 'omega'."\0".'acme']);
    expect(fn () => $this->transactions->run(fn ($tx) => $this->locks->policyRows([
        ['tenant' => $this->tenant, 'scopeKey' => 'missing'],
    ], $tx)))->toThrow(AdmissionRejected::class, 'required_state_missing');
});

it('запрещает ранний adapter guard и откатывает finance при deny-only guard', function (): void {
    expect(fn () => $this->transactions->run(function ($tx): void {
        $state = $this->locks->owner($this->owners, $this->owner, $this->ownerSnapshot, $tx);
        $this->finance->reserve($tx);
        $this->locks->operation($this->operationId, $this->owner, $tx);
        $this->locks->guard('project', new ProjectAdapterGuard($this->db, true), $this->wire, $state, $tx);
    }))->toThrow(AdmissionRejected::class, 'adapter_guard_denied');
    expect($this->db->table('fixture_finance')->value('reserved'))->toBe(0);
    expect(fn () => $this->transactions->run(function ($tx): void {
        $state = $this->locks->owner($this->owners, $this->owner, $this->ownerSnapshot, $tx);
        $this->locks->guard('early', new ProjectAdapterGuard($this->db, false, AdmissionLockTier::OwnerPrefix), $this->wire, $state, $tx);
    }))->toThrow(LogicException::class, 'adapter_guard');
});

it('отказывает external I/O внутри SQL и сохраняет поздний receipt без owner prefix', function (): void {
    $ioCalls = 0;
    $externalIo = function () use (&$ioCalls): void {
        $this->scope->assertOutsideTransaction();
        $ioCalls++;
    };
    $this->transactions->run(function () use ($externalIo): void {
        expect($externalIo)->toThrow(LogicException::class, 'transactionLevel zero');
    });
    expect($ioCalls)->toBe(0);
    $this->db->table('fixture_control')->update(['enabled' => false]);
    $trace = new NamedLockTrace($this->db);
    $this->transactions->run(function ($tx): void {
        $this->locks->rows(AuthorityLockTier::Operation, $this->tenant, [$this->operationId->value], $tx);
        $this->db->table('fixture_receipts')->where('id', 1)->increment('reserved');
    });
    expect($trace->names)->toBe(['pxm_operations'])->and($this->db->table('fixture_receipts')->value('reserved'))->toBe(1);
});

it('проверяет standalone owner по operation root и отклоняет stale snapshot', function (): void {
    $owner = new OwnerRef($this->tenant, 'standalone', $this->operationId->value, 'standalone-token');
    $this->table('operations')->where('id', $this->operationId->value)->update(['owner_type' => 'standalone', 'owner_id' => $this->operationId->value, 'owner_token' => $owner->token()]);
    $authority = new DatabaseOwnerAuthority($this->scope);
    $snapshot = $authority->snapshot($owner);
    $this->transactions->run(function ($tx) use ($authority, $owner, $snapshot): void {
        expect($this->locks->owner($authority, $owner, $snapshot, $tx)->owner)->toBe($owner);
    });
    $this->table('operations')->where('id', $this->operationId->value)->increment('revision');
    expect(fn () => $this->transactions->run(fn ($tx) => $this->locks->owner($authority, $owner, $snapshot, $tx)))->toThrow(AdmissionRejected::class);
});
