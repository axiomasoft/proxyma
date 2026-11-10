<?php

declare(strict_types=1);

namespace Proxyma\Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Orchestra\Testbench\TestCase;
use PDO;
use Proxyma\Contracts\Authorizer;
use Proxyma\Contracts\CatalogCommands;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Catalog\UpsertRouteCommand;
use Proxyma\Domain\Catalog\UpsertRouteProfileCommand;
use Proxyma\Domain\Catalog\UpsertTargetCommand;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Laravel\ProxymaServiceProvider;
use RuntimeException;

require_once __DIR__.'/TenantFixtures.php';

abstract class CatalogTestCase extends TestCase
{
    use TenantFixtures;

    protected Connection $db;

    protected string $fixtureSchema;

    protected TenantKey $tenant;

    protected function getPackageProviders($app): array
    {
        return [ProxymaServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql', 'host' => 'db', 'port' => '5432', 'database' => 'proxyma_test',
            'username' => 'proxyma_test', 'password' => 'proxyma-test-only', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->app['db']->connection('pgsql');
        $identity = $this->db->selectOne('SELECT current_database() AS db, current_user AS usr, (SELECT value FROM proxyma_rig.marker) AS marker');
        if ([$identity->db, $identity->usr, $identity->marker] !== ['proxyma_test', 'proxyma_test', 'proxyma_test_v1']) {
            throw new RuntimeException('Refusing catalog fixture writes outside the marked proxyma_test database.');
        }
        $this->tenant = new TenantKey('acme');
        $this->useTenant($this->tenant);
        $this->fixtureSchema = 'pxm_t_'.bin2hex(random_bytes(8));
        $this->db->statement('CREATE SCHEMA '.$this->fixtureSchema);
        $this->db->statement('SET search_path TO '.$this->fixtureSchema);
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
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

        return compact('route', 'profile', 'target');
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
        $pdo = new PDO('pgsql:host=db;port=5432;dbname=proxyma_test', 'proxyma_test', 'proxyma-test-only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('SET search_path TO '.$this->fixtureSchema);

        return $pdo;
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
