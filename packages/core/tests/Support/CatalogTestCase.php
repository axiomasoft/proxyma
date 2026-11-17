<?php

declare(strict_types=1);

namespace Proxyma\Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Orchestra\Testbench\TestCase;
use Packman\Schema\Laravel\SchemaServiceProvider;
use Packman\Schema\Testing\MigratesSchemaSets;
use Packman\Testkit\Support\Database\SuiteDatabaseConnection;
use Packman\Testkit\Support\Database\TestDatabaseGuard;
use PDO;
use Proxyma\Application\Admission\ClaimSend;
use Proxyma\Application\Admission\ReservationService;
use Proxyma\Application\Admission\TrafficSharePolicy;
use Proxyma\Application\Bindings\BindingEditVersion;
use Proxyma\Application\Bindings\DatabaseBindingCommands;
use Proxyma\Application\Diagnostics\Doctor;
use Proxyma\Application\Execution\OutboundExecutor;
use Proxyma\Application\Execution\OutcomePolicyRegistry;
use Proxyma\Application\Execution\WireSealer;
use Proxyma\Application\Extensions\AttemptObservers;
use Proxyma\Application\Maintenance\MaintenanceSchedule;
use Proxyma\Application\Policy\PolicyResolver;
use Proxyma\Application\Routing\RouteSelector;
use Proxyma\Application\Routing\StrategyRegistry;
use Proxyma\Configuration\PolicyOptions;
use Proxyma\Configuration\ProxymaOptions;
use Proxyma\Contracts\Authorization\Authorizer;
use Proxyma\Contracts\Bindings\BindingCommands;
use Proxyma\Contracts\Catalog\CandidateSnapshots;
use Proxyma\Contracts\Catalog\CatalogCommands;
use Proxyma\Contracts\Execution\OutboundClient;
use Proxyma\Contracts\Policy\PolicyResolver as PolicyResolverContract;
use Proxyma\Contracts\Security\TlsTrustPolicy;
use Proxyma\Diagnostics\ProxymaDoctorCheck;
use Proxyma\Domain\Admission\QueueLimits;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Catalog\UpsertRouteCommand;
use Proxyma\Domain\Catalog\UpsertRouteProfileCommand;
use Proxyma\Domain\Catalog\UpsertTargetCommand;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\HealthThresholds;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\CandidateSnapshotQuery;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Laravel\ProxymaServiceProvider;
use RuntimeException;

require_once __DIR__.'/TenantFixtures.php';

abstract class CatalogTestCase extends TestCase
{
    use MigratesSchemaSets;
    use TenantFixtures;

    protected Connection $db;

    protected string $fixtureSchema;

    protected TenantKey $tenant;

    protected function getPackageProviders($app): array
    {
        return [SchemaServiceProvider::class, ProxymaServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $identity = SuiteDatabaseConnection::pgsqlIdentity();
        TestDatabaseGuard::assertDestructiveSafe($identity);

        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', SuiteDatabaseConnection::laravelPgsqlConfig($identity));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $identity = SuiteDatabaseConnection::pgsqlIdentity();
        TestDatabaseGuard::assertDestructiveSafe($identity);

        $this->db = $this->app['db']->connection('pgsql');

        if (getenv('PROXYMA_DOCKER_RIG') === '1') {
            $row = $this->db->selectOne('SELECT current_database() AS db, current_user AS usr, (SELECT value FROM proxyma_rig.marker) AS marker');
            if ([$row->db, $row->usr, $row->marker] !== ['proxyma_test', 'proxyma_test', 'proxyma_test_v1']) {
                throw new RuntimeException('Refusing catalog fixture writes outside the marked proxyma_test database.');
            }
        } else {
            $row = $this->db->selectOne('SELECT current_database() AS db');
            if ($row->db !== $identity->database) {
                throw new RuntimeException('Refusing catalog fixture writes outside expected test database '.$identity->database.'.');
            }
        }

        $this->tenant = new TenantKey('acme');
        $this->useTenant($this->tenant);
        $this->fixtureSchema = 'pxm_t_'.bin2hex(random_bytes(8));
        $this->db->statement('CREATE SCHEMA '.$this->fixtureSchema);
        $this->db->statement('SET search_path TO '.$this->fixtureSchema);
        $this->migrateSchemaSets(['axiomasoft/proxyma:proxyma'], 'pgsql');
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->db, $this->fixtureSchema)) {
                while ($this->db->transactionLevel() > 0) {
                    $this->db->rollBack();
                }
                $this->db->statement('SET search_path TO public');
                $this->db->statement('DROP SCHEMA '.$this->fixtureSchema.' CASCADE');
            }
        } finally {
            if (isset($this->db)) {
                $this->db->disconnect();
            }
            parent::tearDown();
        }
    }

    protected function actor(int $revision = 0, ?string $key = null, string $reason = 'fixture change'): CatalogMutationActor
    {
        return new CatalogMutationActor(new ActorRef('operator', 'fixture-operator'), $reason, $revision, $key ?? Uuid::random()->value);
    }

    protected function commands(bool $allow = true): CatalogCommands
    {
        $this->app->instance(Authorizer::class, new class($allow) implements Authorizer
        {
            public function __construct(private bool $allow) {}

            public function authorize(ActorRef $actor, TenantKey $tenant, string $ability, Uuid $resourceId): void
            {
                if (! $this->allow) {
                    throw new RuntimeException('fixture authorization denied');
                }
            }
        });

        return $this->app->make(CatalogCommands::class);
    }

    /** @return array{route: Uuid, profile: Uuid, target: Uuid} */
    protected function provision(): array
    {
        $commands = $this->commands();
        $route = Uuid::random();
        $profile = Uuid::random();
        $target = Uuid::random();
        $commands->upsertRoute(new UpsertRouteCommand($route, $this->tenant, 'direct-main', RouteKind::Direct, null, true, EgressIdentity::explicit('nat:fixture'), null, false, $this->actor()));
        $commands->upsertProfile(new UpsertRouteProfileCommand($profile, $this->tenant, 'default', [$route], 'uniform_random', $this->actor()));
        $commands->upsertTarget(new UpsertTargetCommand($target, $this->tenant, 'fixture-origin', CanonicalOrigin::of('https', 'example.test'), 'default', $this->actor()));

        return ['route' => $route, 'profile' => $profile, 'target' => $target];
    }

    protected function table(string $key): Builder
    {
        return $this->db->table(PackageSchema::tables()[$key]);
    }

    protected function rejectedSql(callable $write, string $sqlstate = '23514'): void
    {
        try {
            $this->db->transaction($write);
            $this->fail('Expected PostgreSQL to reject the write.');
        } catch (QueryException $e) {
            $this->assertSame($sqlstate, $e->errorInfo[0]);
        }
    }

    protected function connectionPdo(): PDO
    {
        $identity = SuiteDatabaseConnection::pgsqlIdentity();
        TestDatabaseGuard::assertDestructiveSafe($identity);
        $pdo = new PDO(
            SuiteDatabaseConnection::pgsqlDsn($identity),
            $identity->username,
            SuiteDatabaseConnection::pgsqlPassword(),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $pdo->exec('SET search_path TO '.$this->fixtureSchema);

        return $pdo;
    }

    /** @param array<string, mixed> $overrides */
    protected function reconfigureProxyma(array $overrides): void
    {
        foreach ($overrides as $key => $value) {
            $fullKey = str_starts_with($key, 'proxyma.') || $key === 'app.key' ? $key : 'proxyma.'.$key;
            $this->app['config']->set($fullKey, $value);
        }

        $this->app->forgetScopedInstances();

        $resetTls = array_any(
            array_keys($overrides),
            static fn (string|int $key): bool => $key === 'proxyma' || str_contains((string) $key, 'tls'),
        );

        foreach ([
            ProxymaOptions::class,
            PolicyOptions::class,
            HealthThresholds::class,
            QueueLimits::class,
            ...($resetTls ? [TlsTrustPolicy::class] : []),
            WireSealer::class,
            TrafficSharePolicy::class,
            PolicyResolver::class,
            PolicyResolverContract::class,
            BindingEditVersion::class,
            DatabaseBindingCommands::class,
            BindingCommands::class,
            ClaimSend::class,
            ReservationService::class,
            RouteSelector::class,
            StrategyRegistry::class,
            CandidateSnapshots::class,
            CandidateSnapshotQuery::class,
            AttemptObservers::class,
            OutcomePolicyRegistry::class,
            Doctor::class,
            MaintenanceSchedule::class,
            ProxymaDoctorCheck::class,
            OutboundExecutor::class,
            OutboundClient::class,
        ] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }

    protected function operation(array $catalog): Uuid
    {
        $id = Uuid::random();
        $this->table('operations')->insert(['id' => $id->value, 'tenant_key' => $this->tenant->value,
            'profile_id' => $catalog['profile']->value, 'target_id' => $catalog['target']->value,
            'owner_type' => 'fixture', 'owner_id' => 'owner', 'owner_token' => 'token', 'owner_generation' => 1,
            'deadline' => $this->db->raw("clock_timestamp() + interval '1 minute'"), 'max_physical_attempts' => 3]);

        return $id;
    }
}
