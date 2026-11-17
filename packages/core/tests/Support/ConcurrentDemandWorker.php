<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\PostgresConnection;
use Packman\Testkit\Support\Database\SuiteDatabaseConnection;
use Packman\Testkit\Support\Database\TestDatabaseGuard;
use Proxyma\Application\Admission\AuthorityLocker;
use Proxyma\Application\Admission\DemandQueue;
use Proxyma\Application\Admission\TransactionCoordinator;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Contracts\Authorization\Authorizer;
use Proxyma\Contracts\Authorization\TenantContextResolver;
use Proxyma\Domain\Admission\DemandWait;
use Proxyma\Domain\Admission\QueueLimits;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Execution\OperationCorrelation;
use Proxyma\Domain\Execution\OwnerRef;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\RouteSelection;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Policy\TenantContext;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Admission\DatabaseOwnerAuthority;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\DemandRepository;
use Proxyma\Tests\Support\FixtureWorkBudget;

require dirname(__DIR__, 4).'/vendor/autoload.php';
require __DIR__.'/DemandFixtures.php';

[$script, $schema, $mode, $operationId, $attemptId, $deadline, $label, $capacity, $demandId] = $argv;
if (preg_match('/^pxm_t_[a-f0-9]{16}$/D', $schema) !== 1 || ! in_array($mode, ['enqueue', 'take', 'cancel'], true)
    || ! in_array($label, ['one', 'two'], true) || ! in_array($capacity, ['1', '64'], true)) {
    throw new RuntimeException('Invalid isolated demand worker input.');
}
$identity = SuiteDatabaseConnection::pgsqlIdentity();
TestDatabaseGuard::assertDestructiveSafe($identity);
$pdo = new PDO(SuiteDatabaseConnection::pgsqlDsn($identity), $identity->username, SuiteDatabaseConnection::pgsqlPassword(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (getenv('PROXYMA_DOCKER_RIG') === '1' && $pdo->query('SELECT current_database(), current_user, (SELECT value FROM proxyma_rig.marker)')->fetch(PDO::FETCH_NUM)
    !== ['proxyma_test', 'proxyma_test', 'proxyma_test_v1']) {
    throw new RuntimeException('Refusing demand worker writes outside the marked test database.');
}
$pdo->exec('SET search_path TO '.$schema);
$pdo->exec("SET statement_timeout = '8s'");
$pdo->prepare("SELECT set_config('application_name', ?, false)")->execute(['proxyma-p32-'.$schema.'-'.$label]);
$db = new PostgresConnection($pdo, $identity->database, '', ['name' => 'worker', 'driver' => 'pgsql']);
$db->setTransactionManager($manager = new DatabaseTransactionsManager);
$scope = new ConnectionScope($db, $manager);
$transactions = new TransactionCoordinator($scope);
$locks = new AuthorityLocker($scope);
$repository = new DemandRepository($scope, $locks);
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
$queue = new DemandQueue($transactions, $locks, $repository, new DatabaseOwnerAuthority($scope), new CatalogAccess($resolver, $authorizer), new QueueLimits(maxPendingPerScope: (int) $capacity));
$row = $db->table('pxm_operations')->where('tenant_key', $tenant->value)->where('id', (new Uuid($operationId))->value)->first();
$owner = new OwnerRef($tenant, $row->owner_type, $row->owner_id, $row->owner_token, (int) $row->owner_generation);
$deadlineAt = DateTimeImmutable::createFromFormat('U.u', $deadline);
if ($deadlineAt === false) {
    throw new RuntimeException('Invalid isolated deadline.');
}
$operation = new OperationCorrelation(new Uuid($row->id), $owner, $deadlineAt, $row->external_operation_key);
$request = new WireRequest($operation->operationId, new Uuid($attemptId), new Uuid($row->target_id), 1, 'GET', new SafeUrl('https://example.test/path'),
    new RequestTimeoutPolicy(1, 1, 1, 0, maxAttempts: 3, maxDistinctRoutes: 3), $deadlineAt);
$routeId = $db->table('pxm_route_profile_members')->where('profile_id', $row->profile_id)->value('route_id');
$route = new RouteSelection(new Uuid($routeId), RouteKind::Direct, new Uuid($row->profile_id), null, 1, EgressIdentity::explicit('nat:fixture'));
if ($mode === 'cancel') {
    $queue->cancel($tenant, $operation->operationId, new Uuid($demandId));
    $output = ['status' => 'cancelled'];
} else {
    if ($mode === 'enqueue') {
        $result = $queue->enqueue($request, $operation, $route, new FixtureWorkBudget);
    } else {
        $existing = $repository->byAttempt($tenant, $operation->operationId, $request->physicalAttemptId);
        $ticket = $repository->ticket($existing, $request->url->origin->budgetScopeKey());
        $result = $queue->take($ticket, $request, $operation, $route, new FixtureWorkBudget);
    }
    $output = $result instanceof DemandWait ? ['status' => 'wait', 'reason' => $result->reason]
        : ['status' => $result->state->value, 'id' => $result->demandId->value, 'sequence' => $result->ticketSequence];
}
$output['pid'] = $pdo->query('SELECT pg_backend_pid()')->fetchColumn();
echo json_encode($output, JSON_THROW_ON_ERROR);
