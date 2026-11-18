<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Enums\CheckStatus;
use Packman\Extensions\ExtensionInspector;
use Packman\PackmanServiceProvider;
use Packman\Schema\Contracts\Migration\SchemaSets;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;
use Proxyma\Application\Admission\AuthorityLocker;
use Proxyma\Contracts\Admission\TransactionCoordinator as TransactionCoordinatorContract;
use Proxyma\Contracts\Health\DiagnosticsQueries;
use Proxyma\Diagnostics\ProxymaDoctorCheck;
use Proxyma\Domain\Admission\AuthorityLockTier;
use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Domain\Catalog\UpsertEndpointCommand;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Eloquent\EndpointRecord;
use Proxyma\Plugins\ProxymaCorePlugin;
use Proxyma\Tests\Support\CatalogTestCase;

require_once __DIR__.'/../Support/CatalogTestCase.php';

abstract class PackmanSchemaDoctorPgTestCase extends CatalogTestCase
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return array_values(array_unique(array_merge([PackmanServiceProvider::class], parent::getPackageProviders($app))));
    }
}

uses(PackmanSchemaDoctorPgTestCase::class)->group('pg');

it('registers proxyma central pgsql schema set and reapplies migrations idempotently', function (): void {
    $registry = $this->app->make(SchemaSets::class);
    $set = $registry->get('axiomasoft/proxyma-core:proxyma');

    expect($set->package)->toBe('axiomasoft/proxyma-core')
        ->and($set->name)->toBe('proxyma')
        ->and($set->scope)->toBe(SchemaScope::Central)
        ->and($set->lifecycle)->toBe(SchemaLifecycle::Platform)
        ->and($set->drivers)->toBe(['pgsql']);

    $this->migrateSchemaSets(['axiomasoft/proxyma-core:proxyma'], 'pgsql');

    $capabilities = $this->app->make(DiagnosticsQueries::class)->verifySchemaCapabilities();

    expect($capabilities->healthy)->toBeTrue()
        ->and($capabilities->missingTables)->toBe([])
        ->and($capabilities->missingConstraints)->toBe([]);
});

it('reports weak wire digest key and healthy status through ProxymaDoctorCheck and packman:doctor', function (): void {
    $this->withoutMockingConsoleOutput();

    $weakCode = Artisan::call('packman:doctor', ['--json' => true, '--prefix' => ['proxyma']]);
    /** @var array<string, mixed> $weakReport */
    $weakReport = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($weakCode)->toBe(1)
        ->and($weakReport['schema'])->toBe('packman.doctor/v1')
        ->and($weakReport['ok'])->toBeFalse()
        ->and($weakReport['summary']['error'])->toBeGreaterThanOrEqual(1);

    $this->reconfigureProxyma(['wire.digest_key' => str_repeat('a', 32)]);

    $findings = $this->app->make(ProxymaDoctorCheck::class)->run(new DoctorContext);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->code)->toBe('proxyma.doctor.healthy')
        ->and($findings[0]->status)->toBe(CheckStatus::Ok);

    $healthyCode = Artisan::call('packman:doctor', ['--json' => true, '--prefix' => ['proxyma']]);
    /** @var array<string, mixed> $healthyReport */
    $healthyReport = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($healthyCode)->toBe(0)
        ->and($healthyReport['schema'])->toBe('packman.doctor/v1')
        ->and($healthyReport['ok'])->toBeTrue()
        ->and($healthyReport['summary']['error'])->toBe(0)
        ->and($healthyReport['checks'][0]['key'])->toBe('proxyma.runtime.configuration')
        ->and($healthyReport['checks'][0]['findings'][0]['code'])->toBe('proxyma.doctor.healthy');
});

it('wires compiled TransactionsOptions timeouts and maxAttempts into PostgreSQL TransactionCoordinator and enforces lock_timeout', function (): void {
    $this->reconfigureProxyma([
        'wire.digest_key' => str_repeat('t', 32),
        'transactions' => [
            'lock_timeout' => '50ms',
            'statement_timeout' => '150ms',
            'max_attempts' => 2,
        ],
    ]);

    /** @var TransactionCoordinatorContract $coordinator */
    $coordinator = $this->app->make(TransactionCoordinatorContract::class);
    $locker = $this->app->make(AuthorityLocker::class);
    $catalog = $this->provision();

    $inside = $coordinator->run(function (): array {
        $lock = $this->db->selectOne('SHOW lock_timeout');
        $stmt = $this->db->selectOne('SHOW statement_timeout');

        return [
            'lock_timeout' => $lock->lock_timeout ?? null,
            'statement_timeout' => $stmt->statement_timeout ?? null,
        ];
    });

    expect($inside)->toBe(['lock_timeout' => '50ms', 'statement_timeout' => '150ms']);

    $outsideLock = $this->db->selectOne('SHOW lock_timeout');
    expect($outsideLock->lock_timeout ?? null)->not->toBe('50ms');

    $this->db->transaction(function () use ($coordinator): void {
        $coordinator->run(function (): void {
            expect($this->db->selectOne('SHOW lock_timeout')->lock_timeout)->toBe('50ms')
                ->and($this->db->selectOne('SHOW statement_timeout')->statement_timeout)->toBe('150ms');
        });
    });
    expect($this->db->selectOne('SHOW statement_timeout')->statement_timeout)->not->toBe('150ms');

    try {
        $coordinator->run(fn (): array => $this->db->select('SELECT pg_sleep(0.3)'));
        $this->fail('Expected PostgreSQL statement_timeout SQLSTATE 57014.');
    } catch (QueryException $e) {
        expect($e->errorInfo[0] ?? null)->toBe('57014');
    }

    $attempts = 0;
    try {
        $coordinator->run(function () use (&$attempts): never {
            $attempts++;

            throw new class extends PDOException
            {
                public function __construct()
                {
                    parent::__construct('serialization_failure');
                    $this->errorInfo = ['40001', null, 'serialization_failure'];
                }
            };
        });
        $this->fail('Expected transient serialization failure after configured maxAttempts.');
    } catch (PDOException $e) {
        expect($attempts)->toBe(2)
            ->and($e->errorInfo[0] ?? null)->toBe('40001');
    }

    $targetsTable = PackageSchema::tables()['targets'];
    $blocker = $this->connectionPdo();
    $blocker->beginTransaction();
    try {
        $statement = $blocker->prepare("SELECT id FROM {$targetsTable} WHERE id = ? FOR UPDATE");
        $statement->execute([$catalog['target']->value]);

        $started = microtime(true);
        try {
            $coordinator->run(fn ($tx): array => $locker->rows(AuthorityLockTier::Target, $this->tenant, [$catalog['target']->value], $tx));
            $this->fail('Expected PostgreSQL lock_timeout SQLSTATE 55P03.');
        } catch (QueryException $e) {
            expect($e->errorInfo[0] ?? null)->toBe('55P03')
                ->and(microtime(true) - $started)->toBeLessThan(1.0);
        }
    } finally {
        if ($blocker->inTransaction()) {
            $blocker->rollBack();
        }
    }
});

it('migrates, inspects, and persists catalog entities under a custom configured table_prefix end-to-end', function (): void {
    $auditSchema = 'pxm_audit_'.bin2hex(random_bytes(6));
    $this->db->statement('CREATE SCHEMA '.$auditSchema);

    try {
        $this->db->statement('SET search_path TO '.$auditSchema);
        $this->reconfigureProxyma([
            'wire.digest_key' => str_repeat('a', 32),
            'table_prefix' => 'audit_',
        ]);

        expect(PackageSchema::prefix())->toBe('audit_')
            ->and((new EndpointRecord)->getTable())->toBe('audit_endpoints')
            ->and(PackageSchema::tables()['attempts'])->toBe('audit_attempts')
            ->and(ExtensionInspector::inspect(new ProxymaCorePlugin)->tablePrefix)->toBe('audit_');

        $this->migrateSchemaSets(['axiomasoft/proxyma-core:proxyma'], 'pgsql');

        $capabilities = $this->app->make(DiagnosticsQueries::class)->verifySchemaCapabilities();
        expect($capabilities->healthy)->toBeTrue()
            ->and($capabilities->missingTables)->toBe([])
            ->and($capabilities->missingConstraints)->toBe([]);

        $endpoint = $this->commands()->upsertEndpoint(new UpsertEndpointCommand(
            Uuid::random(),
            $this->tenant,
            ProxyProtocol::Http,
            'audit.example.test',
            8080,
            null,
            $this->actor(),
        ));

        expect($this->db->table('audit_endpoints')->where('id', $endpoint->id->value)->count())->toBe(1)
            ->and($this->db->selectOne("SELECT to_regclass('pxm_endpoints') AS name")->name)->toBeNull();
    } finally {
        $this->reconfigureProxyma(['table_prefix' => 'pxm_']);
        $this->db->statement('SET search_path TO '.$this->fixtureSchema);
        $this->db->statement('DROP SCHEMA IF EXISTS '.$auditSchema.' CASCADE');
    }
});
