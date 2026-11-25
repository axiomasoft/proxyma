<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\PostgresConnection;
use Packman\Testkit\Support\Database\SuiteDatabaseConnection;
use Packman\Testkit\Support\Database\TestDatabaseGuard;
use Proxyma\Application\Admission\AuthorityLocker;
use Proxyma\Application\Admission\DemandQueue;
use Proxyma\Application\Admission\ReservationService;
use Proxyma\Application\Admission\TransactionCoordinator;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Catalog\DatabaseCatalogCommands;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Application\Policy\CoreSettings;
use Proxyma\Application\Policy\SettingRegistry;
use Proxyma\Application\Policy\UpdatePolicy;
use Proxyma\Contracts\Authorization\Authorizer;
use Proxyma\Contracts\Authorization\TenantContextResolver;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\DemandWait;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Catalog\UpsertRouteProfileCommand;
use Proxyma\Domain\Execution\DemandPurpose;
use Proxyma\Domain\Execution\OperationCorrelation;
use Proxyma\Domain\Execution\OwnerRef;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\RouteSelection;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Policy\PolicyScope;
use Proxyma\Domain\Policy\TenantContext;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Domain\Policy\UpdatePolicyCommand;
use Proxyma\Infrastructure\Admission\DatabaseOwnerAuthority;
use Proxyma\Infrastructure\Admission\ReservationCatalogLocks;
use Proxyma\Infrastructure\Database\BudgetRepository;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\DatabaseCatalogQueries;
use Proxyma\Infrastructure\Database\DemandRepository;
use Proxyma\Infrastructure\Database\PermitRepository;
use Proxyma\Tests\Support\FixtureWorkBudget;

require dirname(__DIR__, 4).'/vendor/autoload.php';
require __DIR__.'/DemandFixtures.php';
require __DIR__.'/SelectionFixtures.php';
$payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$schema = $payload['schema'];
if (preg_match('/^pxm_t_[a-f0-9]{16}$/D', $schema) !== 1 || ! in_array($payload['mode'], ['reserve', 'catalog', 'policy'], true)
    || ! in_array($payload['label'], ['one', 'two'], true)) {
    throw new RuntimeException('Invalid reservation worker fixture.');
}
$identity = SuiteDatabaseConnection::pgsqlIdentity();
TestDatabaseGuard::assertDestructiveSafe($identity);
$pdo = new PDO(SuiteDatabaseConnection::pgsqlDsn($identity), $identity->username, SuiteDatabaseConnection::pgsqlPassword(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (getenv('PROXYMA_DOCKER_RIG') === '1' && $pdo->query('SELECT current_database(), current_user, (SELECT value FROM proxyma_rig.marker)')->fetch(PDO::FETCH_NUM) !== ['proxyma_test', 'proxyma_test', 'proxyma_test_v1']) {
    throw new RuntimeException('Refusing writes outside isolated marked proxyma_test database.');
}
$pdo->exec('SET search_path TO '.$schema);
$pdo->exec("SET statement_timeout = '8s'");
$pdo->prepare("SELECT set_config('application_name', ?, false)")->execute(['proxyma-p33-'.$schema.'-'.$payload['label']]);
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
if ($payload['mode'] === 'catalog') {
    $members = $db->table('pxm_route_profile_members')->where('profile_id', $root->profile_id)->orderBy('position')->pluck('route_id')->all();
    $profile = new DatabaseCatalogCommands($db, $access)->upsertProfile(new UpsertRouteProfileCommand(new Uuid($root->profile_id), $tenant, 'default',
        array_map(fn ($id): Uuid => new Uuid($id), $members), 'least_recent_authorized', new CatalogMutationActor($actor, 'fixture mutation', 1, 'profile-mutation')));
    $output = ['status' => 'edited', 'revision' => $profile->revision];
} elseif ($payload['mode'] === 'policy') {
    $revision = new UpdatePolicy($db, $access, $settings)->update(new UpdatePolicyCommand(new Uuid($payload['head']), $tenant, PolicyScope::Tenant,
        'acme', '{"schema_version":1,"operations":{"retry.max_attempts":{"op":"set","value":2}}}',
        new CatalogMutationActor($actor, 'fixture mutation', 1, 'policy-mutation')));
    $output = ['status' => 'edited', 'revision' => $revision->revision];
} else {
    $owners = new DatabaseOwnerAuthority($scope);
    $demands = new DemandRepository($scope, $locks);
    $queue = new DemandQueue($transactions, $locks, $demands, $owners, $access);
    $service = new ReservationService($transactions, $locks, $access, $catalog, $owners, $definitions, $queue, $demands,
        new BudgetRepository($scope), new PermitRepository($scope, $locks), new ReservationCatalogLocks($scope, $locks));
    $owner = new OwnerRef($tenant, $root->owner_type, $root->owner_id, $root->owner_token, (int) $root->owner_generation);
    $deadline = DateTimeImmutable::createFromFormat('U.u', $payload['deadline']);
    $operation = new OperationCorrelation(new Uuid($root->id), $owner, $deadline, $root->external_operation_key,
        purpose: DemandPurpose::from($root->purpose), probeToken: $root->probe_token === null ? null : new Uuid($root->probe_token));
    $request = new WireRequest($operation->operationId, new Uuid($payload['attempt']), new Uuid($root->target_id), 1, 'GET', new SafeUrl('https://example.test/path'),
        new RequestTimeoutPolicy(1, 1, 1, 0, maxAttempts: 3, maxDistinctRoutes: 3), $deadline, purpose: $operation->purpose);
    $route = $catalog->findRoute($tenant, new Uuid($payload['route']));
    $selection = new RouteSelection($route->id, $route->kind, new Uuid($root->profile_id), $route->endpointId, $route->credentialVersion, $route->egress);
    $policy = selectionPolicy($tenant, new Uuid($root->profile_id), new Uuid($root->target_id), layerRevisions: $payload['head'] === null ? [] : ['tenant_db:tenant:acme' => 1]);
    try {
        $input = $service->prepare($request, $operation, $selection, $policy, new FixtureWorkBudget);
        $row = $demands->byAttempt($tenant, $operation->operationId, $request->physicalAttemptId);
        $ticket = $demands->ticket($row, $request->url->origin->budgetScopeKey());
        $result = $service->reserve($ticket, $input);
        $output = $result instanceof DemandWait ? ['status' => 'wait', 'reason' => $result->reason]
            : ['status' => $result->state->value, 'permit' => $result->permitId->value];
    } catch (AdmissionRejected $rejected) {
        $output = ['status' => 'blocked', 'reason' => $rejected->reason];
    }
}
$output['pid'] = $pdo->query('SELECT pg_backend_pid()')->fetchColumn();
echo json_encode($output, JSON_THROW_ON_ERROR);
