<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\PostgresConnection;
use Packman\Testkit\Support\Database\SuiteDatabaseConnection;
use Packman\Testkit\Support\Database\TestDatabaseGuard;
use Proxyma\Application\Admission\AuthorityLocker;
use Proxyma\Application\Admission\ClaimSend;
use Proxyma\Application\Admission\DemandQueue;
use Proxyma\Application\Admission\ReservationService;
use Proxyma\Application\Admission\TransactionCoordinator;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Execution\WireSealer;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Application\Policy\CoreSettings;
use Proxyma\Application\Policy\SettingRegistry;
use Proxyma\Contracts\Authorization\Authorizer;
use Proxyma\Contracts\Authorization\TenantContextResolver;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\QueueLimits;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Catalog\CredentialRef;
use Proxyma\Domain\Execution\DemandPurpose;
use Proxyma\Domain\Execution\OperationCorrelation;
use Proxyma\Domain\Execution\OwnerRef;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\ResolvedSecret;
use Proxyma\Domain\Execution\RouteSelection;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Execution\TlsTrustConfiguration;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Policy\TenantContext;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Admission\DatabaseOwnerAuthority;
use Proxyma\Infrastructure\Admission\ReservationCatalogLocks;
use Proxyma\Infrastructure\Database\BudgetRepository;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\DatabaseCatalogQueries;
use Proxyma\Infrastructure\Database\DemandRepository;
use Proxyma\Infrastructure\Database\PermitRepository;
use Proxyma\Tests\Support\FixtureWorkBudget;

use function Proxyma\Tests\Support\fixtureDestination;

require dirname(__DIR__, 4).'/vendor/autoload.php';
require __DIR__.'/DemandFixtures.php';
require __DIR__.'/SelectionFixtures.php';
require __DIR__.'/DestinationFixtures.php';
$payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$schema = $payload['schema'];
if (preg_match('/^pxm_t_[a-f0-9]{16}$/D', $schema) !== 1 || ! in_array($payload['label'], ['one', 'two'], true)) {
    throw new RuntimeException('Invalid reservation worker fixture.');
}
$identity = SuiteDatabaseConnection::pgsqlIdentity();
TestDatabaseGuard::assertDestructiveSafe($identity);
$pdo = new PDO(SuiteDatabaseConnection::pgsqlDsn($identity), $identity->username, SuiteDatabaseConnection::pgsqlPassword(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (getenv('PROXYMA_DOCKER_RIG') === '1' && $pdo->query('SELECT current_database(), current_user, (SELECT value FROM proxyma_rig.marker)')->fetch(PDO::FETCH_NUM)
    !== ['proxyma_test', 'proxyma_test', 'proxyma_test_v1']) {
    throw new RuntimeException('Refusing reservation writes outside marked test database.');
}
$pdo->exec('SET search_path TO '.$schema);
$pdo->exec("SET statement_timeout = '8s'");
$pdo->prepare("SELECT set_config('application_name', ?, false)")->execute(['proxyma-p34-'.$schema.'-'.$payload['label']]);
$db = new PostgresConnection($pdo, $identity->database, '', ['driver' => 'pgsql', 'name' => 'worker']);
$db->setTransactionManager($manager = new DatabaseTransactionsManager);
$scope = new ConnectionScope($db, $manager);
$transactions = new TransactionCoordinator($scope);
$locks = new AuthorityLocker($scope);
$tenant = new TenantKey('acme');
$actor = new ActorRef('operator', 'fixture-worker');
$resolver = new class($tenant, $actor) implements TenantContextResolver
{
    public function __construct(private TenantKey $tenant, private ActorRef $actor) {}

    public function resolve(): TenantContext
    {
        return new TenantContext($this->tenant, $this->actor);
    }
};
$authorizer = new class implements Authorizer
{
    public function authorize(ActorRef $actor, TenantKey $tenant, string $ability, Uuid $resourceId): void {}
};
$access = new CatalogAccess($resolver, $authorizer);
$catalog = new DatabaseCatalogQueries($db, $access);
$settings = new SettingRegistry(CoreSettings::descriptors());
$definitions = new DefinitionRegistry($settings);
$definitions->freeze();

$root = $db->table('pxm_operations')->where('tenant_key', 'acme')->where('id', (new Uuid($payload['operation']))->value)->first();
$owners = new DatabaseOwnerAuthority($scope);
$demands = new DemandRepository($scope, $locks);
$budgets = new BudgetRepository($scope);
$permits = new PermitRepository($scope, $locks);
$catalogLocks = new ReservationCatalogLocks($scope, $locks);
$queue = new DemandQueue($transactions, $locks, $demands, $owners, $access, new QueueLimits);
$service = new ReservationService($transactions, $locks, $access, $catalog, $owners, $definitions, $queue,
    $demands, $budgets, $permits, $catalogLocks, new QueueLimits);
$sealer = new WireSealer($scope, 'fixture-only-32-byte-hmac-key-value');
$claims = new ClaimSend($transactions, $locks, $owners, $catalogLocks, $demands, $budgets, $permits, $definitions, $sealer);
$owner = new OwnerRef($tenant, $root->owner_type, $root->owner_id, $root->owner_token, (int) $root->owner_generation);
$deadline = DateTimeImmutable::createFromFormat('U.u', $payload['deadline']);
$operation = new OperationCorrelation(new Uuid($root->id), $owner, $deadline, $root->external_operation_key,
    purpose: DemandPurpose::from($root->purpose), probeToken: $root->probe_token === null ? null : new Uuid($root->probe_token));
$request = new WireRequest($operation->operationId, new Uuid($payload['attempt']), new Uuid($root->target_id), 1, 'GET', new SafeUrl('https://example.test/path'),
    new RequestTimeoutPolicy(1, 1, 1, 0, maxAttempts: 3, maxDistinctRoutes: 3), $deadline, purpose: $operation->purpose);
$route = $catalog->findRoute($tenant, new Uuid($payload['route']));
$credential = $route->endpointId === null ? null : new ResolvedSecret(new CredentialRef('fixture:proxy', 1), 'fixture', 'fixture-proxy-secret');
$wire = $sealer->seal($request, $route, fixtureDestination($scope, $request), new TlsTrustConfiguration, $credential);
$selection = new RouteSelection($route->id, $route->kind, new Uuid($root->profile_id), $route->endpointId, $route->credentialVersion, $route->egress);
$policy = selectionPolicy($tenant, new Uuid($root->profile_id), new Uuid($root->target_id));
$input = $service->prepare($wire->request, $operation, $selection, $policy, new FixtureWorkBudget);
$demand = $demands->byAttempt($tenant, $operation->operationId, $request->physicalAttemptId);
$row = $db->table('pxm_permits')->where('id', (new Uuid($payload['permit']))->value)->first();
$permit = $permits->result($row, $demands->ticket($demand, $request->url->origin->budgetScopeKey()));
if ($payload['cas_gate']) {
    $gated = false;
    $db->beforeExecuting(function ($sql) use ($db, &$gated): void {
        if (! $gated && str_contains($sql, 'WITH instant AS MATERIALIZED')) {
            $gated = true;
            $db->select("SELECT pg_advisory_xact_lock(hashtextextended('fixture:claim-cas-gate', 0))");
        }
    });
}
$bytes = 0;
try {
    $candidate = $claims->claim($permit, $input, $wire);
    if ($payload['commit_gate']) {
        $db->select("SELECT pg_advisory_lock(hashtextextended('fixture:claim-commit-gate', 0))");
        $db->select("SELECT pg_advisory_unlock(hashtextextended('fixture:claim-commit-gate', 0))");
    }
    $capability = $claims->activate($candidate);
    $capability->consume();
    $bytes++;
    $status = 'sent';
} catch (AdmissionRejected $rejected) {
    $status = $rejected->reason;
} catch (LogicException $exception) {
    if (! str_contains($exception->getMessage(), 'expired')) {
        throw $exception;
    }
    $status = 'expired';
}
echo json_encode(['status' => $status, 'bytes' => $bytes, 'pid' => $pdo->query('SELECT pg_backend_pid()')->fetchColumn()], JSON_THROW_ON_ERROR);
