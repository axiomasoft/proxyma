<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\PostgresConnection;
use Proxyma\Application\Admission\AuthorityLocker;
use Proxyma\Application\Admission\ClaimSend;
use Proxyma\Application\Admission\DemandQueue;
use Proxyma\Application\Admission\QuotaService;
use Proxyma\Application\Admission\ReservationService;
use Proxyma\Application\Admission\TrafficSharePolicy;
use Proxyma\Application\Admission\TransactionCoordinator;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Catalog\DatabaseCatalogCommands;
use Proxyma\Application\Execution\WireSealer;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Application\Policy\CoreSettings;
use Proxyma\Application\Policy\SettingRegistry;
use Proxyma\Application\Policy\UpdatePolicy;
use Proxyma\Application\Recovery\CompletionCoordinator;
use Proxyma\Application\Recovery\Reconciler;
use Proxyma\Contracts\Authorizer;
use Proxyma\Contracts\TenantContextResolver;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\AuthorityLockTier;
use Proxyma\Domain\Admission\DemandWait;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Catalog\CredentialRef;
use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Domain\Catalog\RetireCatalogEntityCommand;
use Proxyma\Domain\Catalog\UpsertEndpointCommand;
use Proxyma\Domain\Catalog\UpsertRouteProfileCommand;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\DecodedTextBody;
use Proxyma\Domain\Execution\DemandPurpose;
use Proxyma\Domain\Execution\LateReceiptClaim;
use Proxyma\Domain\Execution\OperationCorrelation;
use Proxyma\Domain\Execution\OwnerRef;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\ResolvedSecret;
use Proxyma\Domain\Execution\ResolvedUrl;
use Proxyma\Domain\Execution\ResponseDescriptor;
use Proxyma\Domain\Execution\RouteSelection;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Execution\TlsTrustConfiguration;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Health\HealthEpochMap;
use Proxyma\Domain\Policy\PolicyScope;
use Proxyma\Domain\Policy\TenantContext;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Domain\Policy\UpdatePolicyCommand;
use Proxyma\Infrastructure\Admission\DatabaseOwnerAuthority;
use Proxyma\Infrastructure\Admission\ReservationCatalogLocks;
use Proxyma\Infrastructure\Database\BudgetRepository;
use Proxyma\Infrastructure\Database\CompletionObservationReducer;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\DatabaseCatalogQueries;
use Proxyma\Infrastructure\Database\DemandRepository;
use Proxyma\Infrastructure\Database\PermitRepository;
use Proxyma\Infrastructure\Database\QuotaRepository;
use Proxyma\Infrastructure\Database\ReconcileRepository;
use Proxyma\Tests\Support\FinancialLedger;
use Proxyma\Tests\Support\FixtureWorkBudget;
use Proxyma\Tests\Support\ProjectOwnerAuthority;

require dirname(__DIR__, 4).'/vendor/autoload.php';
require __DIR__.'/DemandFixtures.php';
require __DIR__.'/SelectionFixtures.php';
require __DIR__.'/TransactionFixtures.php';
require __DIR__.'/DestinationFixtures.php';
set_exception_handler(static function (Throwable $error): void {
    // Fatal output must identify a failed connection without exposing SQL bindings or secrets.
    fwrite(STDERR, json_encode(['error_type' => $error::class, 'sqlstate' => $error instanceof PDOException ? ($error->errorInfo[0] ?? (string) $error->getCode()) : null,
        'source' => basename($error->getFile()), 'line' => $error->getLine()], JSON_THROW_ON_ERROR));
    exit(1);
});
$payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$schema = $payload['schema'];
if (preg_match('/^pxm_t_[a-f0-9]{16}$/D', $schema) !== 1 || ! in_array($payload['label'], ['one', 'two'], true)) {
    throw new RuntimeException('Invalid authority worker fixture.');
}
$pdo = new PDO('pgsql:host=db;port=5432;dbname=proxyma_test', 'proxyma_test', 'proxyma-test-only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($pdo->query('SELECT current_database(),current_user,(SELECT value FROM proxyma_rig.marker)')->fetch(PDO::FETCH_NUM) !== ['proxyma_test', 'proxyma_test', 'proxyma_test_v1']) {
    throw new RuntimeException('Refusing authority fixture outside marked proxyma_test.');
}
$pdo->exec('SET search_path TO '.$schema.',pg_catalog');
$pdo->exec("SET statement_timeout='12s'");
$pdo->prepare("SELECT set_config('application_name',?,false)")->execute(['proxyma-p43-'.$schema.'-'.$payload['label']]);
$db = new PostgresConnection($pdo, 'proxyma_test', '', ['driver' => 'pgsql', 'name' => 'worker']);
$db->setTransactionManager($manager = new DatabaseTransactionsManager);
$scope = new ConnectionScope($db, $manager);
$transactions = new TransactionCoordinator($scope);
$locks = new AuthorityLocker($scope);
$lockNames = [];
$db->beforeExecuting(function ($sql) use (&$lockNames): void {
    if (str_contains(strtolower($sql), 'for update') && preg_match('/from\s+"?([a-z_]+)"?/i', $sql, $match)) {
        $lockNames[] = $match[1];
    }
});
$tenant = new TenantKey('acme');
$actor = new ActorRef('operator', 'fixture-worker');
$resolver = new class($tenant, $actor)implements TenantContextResolver
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
$owners = ($payload['project_prefix'] ?? false) ? new ProjectOwnerAuthority($scope) : new DatabaseOwnerAuthority($scope);
$demands = new DemandRepository($scope, $locks);
$budgets = new BudgetRepository($scope);
$permits = new PermitRepository($scope, $locks);
$quotas = new QuotaRepository($scope);
$traffic = new TrafficSharePolicy;
$quota = new QuotaService($transactions, $locks, $access, $quotas, $demands, $budgets, $traffic);
$recovery = new ReconcileRepository($scope, $locks, $demands, $budgets, $quotas);
$commands = new DatabaseCatalogCommands($db, $access);
$root = $db->table('pxm_operations')->where('id', (new Uuid($payload['operation']))->value)->first();
$action = $payload['action'];
$bytes = 0;
$attempts = 0;
$mutation = new CatalogMutationActor($actor, 'authority matrix fixture', 1, 'p43-'.$action);
try {
    switch ($action) {
        case 'profile':
            $ids = $db->table('pxm_route_profile_members')->where('profile_id', $root->profile_id)->where('route_id', '<>', $payload['route'])->orderBy('position')->pluck('route_id')->all();
            $commands->upsertProfile(new UpsertRouteProfileCommand(new Uuid($root->profile_id), $tenant, 'default', array_map(static fn ($id) => new Uuid($id), $ids), 'uniform_random', $mutation));
            $status = 'changed';
            break;
        case 'credential':
            $endpoint = $db->table('pxm_endpoints')->where('id', $payload['endpoint'])->first();
            $commands->upsertEndpoint(new UpsertEndpointCommand(new Uuid($endpoint->id), $tenant, ProxyProtocol::from($endpoint->protocol), $endpoint->host, (int) $endpoint->port,
                new CredentialRef('fixture:proxy', 2), $mutation));
            $status = 'changed';
            break;
        case 'target':
            $commands->retireTarget(new RetireCatalogEntityCommand(new Uuid($root->target_id), $tenant, $mutation));
            $status = 'changed';
            break;
        case 'policy':
            (new UpdatePolicy($db, $access, $settings))->update(new UpdatePolicyCommand(new Uuid($payload['head']), $tenant, PolicyScope::Tenant, 'acme',
                '{"schema_version":1,"operations":{"retry.max_attempts":{"op":"set","value":2}}}', $mutation));
            $status = 'changed';
            break;
        case 'budget':
            $quota->configure($tenant, new Uuid($payload['budget']), $payload['hour'] ?? 0, $payload['day'] ?? 0, 2);
            $status = 'changed';
            break;
        case 'cancel':
            $transactions->run(function ($tx) use ($locks, $owners, $db, $tenant, $root): void {
                $locks->participant($owners, AuthorityLockTier::Owner, $root->owner_id, $tx);
                foreach (['fixture_control', 'fixture_subject', 'fixture_work', 'fixture_attempt'] as $table) {
                    $db->table($table)->orderBy('id')->lockForUpdate()->first();
                }
                $locks->rows(AuthorityLockTier::Operation, $tenant, [$root->id], $tx);
                $db->table('fixture_control')->update(['enabled' => false, 'revision' => 2]);
                $db->table('pxm_operations')->where('id', $root->id)->update(['status' => 'cancelled', 'revoked_at' => $db->raw('clock_timestamp()'), 'revision' => 2]);
            });
            $status = 'cancelled';
            break;
        case 'reconcile':
            (new Reconciler($transactions, $recovery, $access))->run($tenant);
            $status = 'reconciled';
            break;
        case 'receipt':
            $attempt = $db->table('pxm_attempts')->where('operation_id', $root->id)->where('physical_attempt_id', $payload['attempt'])->first();
            $receipt = new LateReceiptClaim(new Uuid($root->id), new Uuid($payload['attempt']), new ContentHash($attempt->wire_fingerprint), 'matrix-receipt',
                new ContentHash(hash('sha256', 'fixture')), new HealthEpochMap(json_decode($attempt->snapshot, true)['epochs']),
                new ResponseDescriptor(200, [], new DecodedTextBody('fixture'), 7, 7));
            $status = (new CompletionCoordinator($transactions, $recovery, $access, $resolver, new CompletionObservationReducer($scope)))->recordLateReceipt($receipt)->outcome->value;
            break;
        case 'deadlock':
            $transactions->run(function ($tx) use (&$attempts, $db, $payload): void {
                $attempts++;
                $first = $payload['label'] === 'one' ? 1 : 2;
                $last = 3 - $first;
                $db->table('fixture_finance')->where('id', $first)->lockForUpdate()->first();
                $db->table('fixture_finance')->where('id', $first)->increment('reserved');
                $db->select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['fixture:p43:deadlock:'.$payload['label']]);
                $db->table('fixture_finance')->where('id', $last)->lockForUpdate()->first();
            }, 3);
            $status = 'retried';
            break;
        default:
            $queue = new DemandQueue($transactions, $locks, $demands, $owners, $access);
            $service = new ReservationService($transactions, $locks, $access, $catalog, $owners, $definitions, $queue, $demands, $budgets, $permits,
                new ReservationCatalogLocks($scope, $locks), quotaRepository: $quotas);
            $sealer = new WireSealer($scope, 'fixture-only-32-byte-hmac-key-value');
            $claims = new ClaimSend($transactions, $locks, $owners, new ReservationCatalogLocks($scope, $locks), $demands, $budgets, $permits, $definitions, $sealer, quotaService: $quota, quotaRepository: $quotas);
            $owner = new OwnerRef($tenant, $root->owner_type, $root->owner_id, $root->owner_token, (int) $root->owner_generation);
            $deadline = DateTimeImmutable::createFromFormat('U.u', $payload['deadline']);
            $operation = new OperationCorrelation(new Uuid($root->id), $owner, $deadline, $root->external_operation_key,
                purpose: DemandPurpose::from($root->purpose), probeToken: $root->probe_token === null ? null : new Uuid($root->probe_token));
            $request = new WireRequest(new Uuid($root->id), new Uuid($payload['attempt']), new Uuid($root->target_id), 1, 'GET', new SafeUrl('https://example.test/path'),
                new RequestTimeoutPolicy(1, 1, 1, 0, maxAttempts: 8, maxDistinctRoutes: 8), $deadline, purpose: $operation->purpose);
            $route = $catalog->findRoute($tenant, new Uuid($payload['route']));
            $credential = $route->endpointId === null ? null : new ResolvedSecret(new CredentialRef('fixture:proxy', 1), 'fixture', 'fixture-proxy-secret');
            $wire = $sealer->seal($request, $route, \Proxyma\Tests\Support\fixtureDestination($scope, $request), new TlsTrustConfiguration, $credential);
            $selection = new RouteSelection($route->id, $route->kind, new Uuid($root->profile_id), $route->endpointId, $route->credentialVersion, $route->egress);
            $policy = selectionPolicy($tenant, new Uuid($root->profile_id), new Uuid($root->target_id));
            $input = $service->prepare($wire->request, $operation, $selection, $policy, new FixtureWorkBudget);
            $demand = $demands->byAttempt($tenant, $operation->operationId, $request->physicalAttemptId);
            $ticket = $demands->ticket($demand, $request->url->origin->budgetScopeKey());
            if ($action === 'reserve') {
                $result = $service->reserve($ticket, $input);
                $status = $result instanceof DemandWait ? $result->reason : 'reserved';
                break;
            }
            $permit = $permits->result($db->table('pxm_permits')->where('id', $payload['permit'])->first(), $ticket);
            if ($action === 'joint_claim') {
                $claims->prepare($permit, $input, $wire);
                $finance = new FinancialLedger($db, $locks);
                $candidate = $transactions->run(function ($tx) use (&$attempts, $locks, $owners, $owner, $input, $finance, $claims, $permit, $wire, $payload, $db, $scope) {
                    $attempts++;
                    $state = $locks->owner($owners, $owner, $input->ownerSnapshot, $tx);
                    $finance->reserve($tx);
                    $candidate = $claims->claimSendLocked($permit, $input, $wire, $tx, $state);
                    if ($attempts <= ($payload['fault_attempts'] ?? 0)) {
                        $state = $payload['fault_state'] ?? '40P01';
                        if (! in_array($state, ['40P01', '40001', '23514'], true)) {
                            throw new RuntimeException('Invalid fixture SQLSTATE.');
                        }
                        if (($payload['fault_mode'] ?? 'query') === 'commit') {
                            $db->select("SELECT set_config('fixture.fail_state',?,true)", [$state]);
                        } elseif (($payload['fault_mode'] ?? 'query') === 'after_commit') {
                            $scope->root()->addCallback(static function () use ($db, $state): void {
                                $db->unprepared("DO \$\$ BEGIN RAISE EXCEPTION 'fixture after commit conflict' USING ERRCODE='{$state}'; END \$\$");
                            });
                        } else {
                            $db->unprepared("DO \$\$ BEGIN RAISE EXCEPTION 'fixture retry fault' USING ERRCODE='{$state}'; END \$\$");
                        }
                    }

                    return $candidate;
                }, 2);
            } else {
                $candidate = $claims->claim($permit, $input, $wire);
            }
            $claims->activate($candidate)->consume();
            if ($db->transactionLevel() !== 0) {
                throw new RuntimeException('Fixture dispatch inside transaction.');
            }
            $db->table('fixture_wire_receipts')->insert(['physical_attempt_id' => $payload['attempt'], 'bytes' => 1]);
            $bytes = 1;
            $status = 'sent';
    }
} catch (AdmissionRejected $denial) {
    $status = $denial->reason;
} catch (PDOException $error) {
    if (! in_array($error->getCode(), ['40P01', '40001', '23514'], true)) {
        throw $error;
    }
    $status = ($payload['fault_mode'] ?? null) === 'after_commit' ? 'post_commit_failure' : ($error->getCode() === '23514' ? 'prerequisite_failed' : 'deadlock_exhausted');
}
echo json_encode(['status' => $status, 'bytes' => $bytes, 'attempts' => $attempts, 'pg_pid' => (int) $pdo->query('SELECT pg_backend_pid()')->fetchColumn(),
    'os_pid' => getmypid(), 'lock_names' => $lockNames], JSON_THROW_ON_ERROR);
