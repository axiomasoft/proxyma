<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Proxyma\Application\Admission\TransactionCoordinator;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Diagnostics\Doctor;
use Proxyma\Application\Execution\OutcomePolicyRegistry;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Application\Policy\SettingRegistry;
use Proxyma\Application\Routing\StrategyRegistry;
use Proxyma\Contracts\Authorization\Authorizer;
use Proxyma\Contracts\Security\SecretResolver;
use Proxyma\Contracts\Authorization\TenantContextResolver;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\DecodedTextBody;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\ResponseDescriptor;
use Proxyma\Domain\Execution\RouteSelectionContext;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Domain\Policy\TenantContext;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Security\DenyAuthorizer;
use Proxyma\Laravel\ProxymaServiceProvider;

$directory = $argv[1];
require $directory.'/vendor/autoload.php';
$app = require $directory.'/bootstrap/app.php';
$queries = 0;
$app->make('events')->listen(QueryExecuted::class, function () use (&$queries): void {
    $queries++;
});
$app->bind(SecretResolver::class, static function () {
    throw new RuntimeException('secret resolution during bootstrap');
});
$app->make(Kernel::class)->bootstrap();
$registry = $app->make(DefinitionRegistry::class);
assert($queries === 0);
assert(strlen($registry->fingerprint()) === 64);
assert(! class_exists('App\\Models\\User'));
assert(! class_exists('Filament\\Panel'));
assert($app->getProvider(ProxymaServiceProvider::class) !== null);
assert($app->make(Authorizer::class) instanceof DenyAuthorizer);

if (str_ends_with($directory, '/custom')) {
    $subject = new SubjectRef(new TenantKey('one'), 'document', 'fixture');
    $resolved = $registry->subjectResolver('consumer.subjects')->resolver->resolve($subject);
    assert($resolved->tenant->value === 'one');
    $subject2 = new SubjectRef(new TenantKey('two'), 'document', 'fixture');
    assert($registry->subjectResolver('consumer.subjects')->resolver->resolve($subject2)->tenant->value === 'two');
    $strategy = $app->make(StrategyRegistry::class)->get('consumer.first');
    $wire = new WireRequest(Uuid::random(), Uuid::random(), Uuid::random(), 1, 'GET', new SafeUrl('https://example.test/'),
        new RequestTimeoutPolicy(100, 100, 100, 0), new DateTimeImmutable('+1 minute'));
    assert($strategy->select([], $wire, new RouteSelectionContext) === null);
    $attempt = AttemptResult::delivered(new ResponseDescriptor(404, [], new DecodedTextBody('fixture'), 7, 7));
    assert($app->make(OutcomePolicyRegistry::class)->resolve('consumer.accept')->classify($wire, $attempt)->businessMeaning === 'consumer_response');
    assert(in_array('consumer.first', $app->make(SettingRegistry::class)->descriptor('route.strategy')->allowedValues, true));
}

$app['config']->set('database.connections.pgsql.host', 'db');
$app['config']->set('database.connections.pgsql.port', '5432');
$db = $app['db']->connection('pgsql');
$identity = $db->selectOne('SELECT current_database() AS db, current_user AS usr, (SELECT value FROM proxyma_rig.marker) AS marker');
assert([$identity->db, $identity->usr, $identity->marker] === ['proxyma_test', 'proxyma_test', 'proxyma_test_v1']);
$schema = 'pxm_consumer_'.bin2hex(random_bytes(8));
$db->statement('CREATE SCHEMA '.$schema);
$db->statement('SET search_path TO '.$schema);
try {
    $doctor = $app->make(Doctor::class)->inspect();
    assert($doctor['schema']->healthy === false);
    assert(in_array('pxm_routes', $doctor['schema']->missingTables, true));
    assert($doctor['network_io'] === false);
    $app->instance(TenantContext::class, new TenantContext(new TenantKey('one'), new ActorRef('fixture', 'one')));
    $app->scoped(TenantContextResolver::class, fn ($app) => new class($app->make(TenantContext::class)) implements TenantContextResolver
    {
        public function __construct(private TenantContext $context) {}

        public function resolve(): TenantContext
        {
            return $this->context;
        }
    });
    $access = $app->make(CatalogAccess::class);
    $app->instance(Authorizer::class, new class implements Authorizer
    {
        public function authorize(ActorRef $actor, TenantKey $tenant, string $ability, Uuid $resourceId): void {}
    });
    $access->authorize(new TenantKey('one'), 'catalog.route.read', Uuid::random());
    $first = $app->make(TransactionCoordinator::class);
    $app->forgetScopedInstances();
    $app->instance(TenantContext::class, new TenantContext(new TenantKey('two'), new ActorRef('fixture', 'two')));
    $second = $app->make(TransactionCoordinator::class);
    assert($first !== $second);
    assert($app->make(TenantContextResolver::class)->resolve()->tenant->value === 'two');
    $denied = false;
    try {
        $access->authorize(new TenantKey('one'), 'catalog.route.read', Uuid::random());
    } catch (AuthorizationDenied) {
        $denied = true;
    }
    assert($denied);
    $access->authorize(new TenantKey('two'), 'catalog.route.read', Uuid::random());
} finally {
    $db->statement('SET search_path TO public');
    $db->statement('DROP SCHEMA '.$schema.' CASCADE');
}
echo json_encode(['boot_queries' => 0, 'missing_schema_reported' => true, 'worker_isolated' => true, 'registry' => $registry->fingerprint()], JSON_THROW_ON_ERROR).PHP_EOL;
