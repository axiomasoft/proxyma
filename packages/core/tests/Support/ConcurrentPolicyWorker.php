<?php

declare(strict_types=1);

use Illuminate\Database\PostgresConnection;
use Packman\Testkit\Support\Database\SuiteDatabaseConnection;
use Packman\Testkit\Support\Database\TestDatabaseGuard;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Policy\CoreSettings;
use Proxyma\Application\Policy\SettingRegistry;
use Proxyma\Application\Policy\UpdatePolicy;
use Proxyma\Contracts\Authorization\Authorizer;
use Proxyma\Contracts\Authorization\TenantContextResolver;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\PolicyConflict;
use Proxyma\Domain\Policy\PolicyScope;
use Proxyma\Domain\Policy\TenantContext;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Domain\Policy\UpdatePolicyCommand;

require dirname(__DIR__, 4).'/vendor/autoload.php';

[$script, $schema, $headId, $worker] = $argv;
if (preg_match('/^pxm_t_[a-f0-9]{16}$/D', $schema) !== 1 || ! in_array($worker, ['one', 'two'], true)) {
    throw new RuntimeException('Invalid isolated worker fixture.');
}
$identity = SuiteDatabaseConnection::pgsqlIdentity();
TestDatabaseGuard::assertDestructiveSafe($identity);
$pdo = new PDO(SuiteDatabaseConnection::pgsqlDsn($identity), $identity->username, SuiteDatabaseConnection::pgsqlPassword(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (getenv('PROXYMA_DOCKER_RIG') === '1' && $pdo->query('SELECT current_database(), current_user, (SELECT value FROM proxyma_rig.marker)')->fetch(PDO::FETCH_NUM) !== ['proxyma_test', 'proxyma_test', 'proxyma_test_v1']) {
    throw new RuntimeException('Refusing writes outside isolated marked proxyma_test database.');
}
$pdo->exec('SET search_path TO '.$schema);
$name = 'proxyma-p21-'.$schema.'-'.$worker;
$pdo->prepare("SELECT set_config('application_name', ?, false)")->execute([$name]);
$db = new PostgresConnection($pdo, $identity->database);
$tenant = new TenantKey('acme');
$actor = new ActorRef('operator', 'fixture-operator');
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
$service = new UpdatePolicy($db, new CatalogAccess($resolver, $authorizer), new SettingRegistry(CoreSettings::descriptors()));
$command = new UpdatePolicyCommand(new Uuid($headId), $tenant, PolicyScope::Tenant, 'acme',
    json_encode(['schema_version' => 1, 'operations' => ['retry.max_attempts' => ['op' => 'set', 'value' => $worker === 'one' ? 2 : 3]]], JSON_THROW_ON_ERROR),
    new CatalogMutationActor($actor, 'concurrent update', 1, 'parallel-'.$worker));
try {
    $result = $service->update($command);
    echo json_encode(['status' => 'updated', 'revision' => $result->revision], JSON_THROW_ON_ERROR);
} catch (PolicyConflict) {
    echo json_encode(['status' => 'conflict'], JSON_THROW_ON_ERROR);
}
