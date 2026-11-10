<?php

declare(strict_types=1);

use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Policy\CoreSettings;
use Proxyma\Application\Policy\SettingRegistry;
use Proxyma\Application\Policy\SettingRegistryFactory;
use Proxyma\Contracts\PolicyCommands;
use Proxyma\Contracts\SettingDefinitionProvider;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\InvalidSettingPatch;
use Proxyma\Domain\Policy\PatchOperation;
use Proxyma\Domain\Policy\PolicyConflict;
use Proxyma\Domain\Policy\PolicyScope;
use Proxyma\Domain\Policy\PolicyStorageFailure;
use Proxyma\Domain\Policy\SettingChange;
use Proxyma\Domain\Policy\SettingDescriptor;
use Proxyma\Domain\Policy\SettingMerge;
use Proxyma\Domain\Policy\SettingPatch;
use Proxyma\Domain\Policy\SettingType;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Domain\Policy\UpdatePolicyCommand;
use Proxyma\Tests\Support\CatalogTestCase;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Support/CatalogTestCase.php';

uses(CatalogTestCase::class);

final class FixtureSettingsProvider implements SettingDefinitionProvider
{
    public function settings(): array
    {
        return [new SettingDescriptor('fixture.limit', SettingType::Integer, 1, false, [PolicyScope::Tenant], SettingMerge::Minimum, 1, 5, 'policy.fixture.edit')];
    }
}

function policyPayload(array $operations, int $version = 1): string
{
    return json_encode(['schema_version' => $version, 'operations' => (object) $operations], JSON_THROW_ON_ERROR);
}

it('сохраняет set null и пустой список отдельно от disable и удаляет override через inherit', function (): void {
    $this->commands();
    $service = $this->app->make(PolicyCommands::class);
    $head = Uuid::random();
    $first = new UpdatePolicyCommand($head, $this->tenant, PolicyScope::Tenant, 'acme', policyPayload([
        'destinations.allowed' => ['op' => 'set', 'value' => null],
        'routes.denied' => ['op' => 'set', 'value' => []],
        'retry.delays_ms' => ['op' => 'disable'],
        'timeout.connect_ms' => ['op' => 'set', 'value' => 2500],
    ]), $this->actor(0, 'policy-first'));
    $created = $service->update($first);
    expect($created->revision)->toBe(1)->and($created->patch->changes['destinations.allowed']->value)->toBeNull()
        ->and($created->patch->changes['routes.denied']->value)->toBe([])
        ->and($created->patch->changes['retry.delays_ms']->operation)->toBe(PatchOperation::Disable);
    $second = new UpdatePolicyCommand($head, $this->tenant, PolicyScope::Tenant, 'acme', policyPayload([
        'destinations.allowed' => ['op' => 'inherit'],
        'retry.delays_ms' => ['op' => 'set', 'value' => []],
        'timeout.connect_ms' => ['op' => 'inherit'],
    ]), $this->actor(1, 'policy-second'));
    $updated = $service->update($second);
    expect($updated->revision)->toBe(2)->and($updated->patch->changes)->not->toHaveKeys(['destinations.allowed', 'timeout.connect_ms'])
        ->and($updated->patch->changes['retry.delays_ms']->operation)->toBe(PatchOperation::Set)
        ->and($updated->patch->changes['retry.delays_ms']->value)->toBe([])
        ->and($updated->patch->changes['routes.denied']->value)->toBe([]);
    expect($service->update($first)->revision)->toBe(1)->and($this->table('policy_revisions')->count())->toBe(2)
        ->and((int) $this->table('policy_heads')->value('revision'))->toBe(2);
    $audit = $this->table('audit_events')->where('entity_type', 'policy_heads')->where('revision', 2)->first();
    $detail = json_decode($audit->result, true, flags: JSON_THROW_ON_ERROR);
    expect($detail['before_fields'])->toBe(['destinations.allowed', 'retry.delays_ms', 'timeout.connect_ms'])
        ->and($detail['after_fields'])->toBe(['retry.delays_ms']);
    $this->rejectedSql(fn () => $this->table('policy_revisions')->update(['definition' => '{}']));
});

it('не берёт SQL locks и не оставляет частичную запись при неизвестном op key типе bounds schema или ciphertext', function (): void {
    $this->commands();
    $service = $this->app->make(PolicyCommands::class);
    $head = Uuid::random();
    $good = ['timeout.connect_ms' => ['op' => 'set', 'value' => 500]];
    $bad = [
        policyPayload($good + ['unknown.key' => ['op' => 'set', 'value' => 1]]),
        policyPayload(['timeout.connect_ms' => ['op' => 'merge', 'value' => 1]]),
        policyPayload(['timeout.connect_ms' => ['op' => 'set']]),
        policyPayload(['timeout.connect_ms' => ['op' => 'inherit', 'value' => 1]]),
        policyPayload(['timeout.connect_ms' => ['op' => 'set', 'value' => null]]),
        policyPayload(['timeout.connect_ms' => ['op' => 'set', 'value' => '1']]),
        policyPayload(['timeout.connect_ms' => ['op' => 'set', 'value' => 0]]),
        policyPayload(['timeout.connect_ms' => ['op' => 'set', 'value' => 120001]]),
        policyPayload(['timeout.connect_ms' => ['op' => 'disable']]),
        policyPayload(['route.allow_direct' => ['op' => 'set', 'value' => 1]]),
        policyPayload(['routes.denied' => ['op' => 'set', 'value' => (object) []]]),
        policyPayload(['routes.denied' => ['op' => 'set', 'value' => ['eyJpdiI6IkVuY3J5cHRlZCJ9']]]),
        policyPayload(['route.strategy' => ['op' => 'set', 'value' => 'unknown_registry_id']]),
        policyPayload(['credential.value' => ['op' => 'set', 'value' => 'ciphertext']]),
        policyPayload($good, 2),
        '{"schema_version":1,"operations":[]}',
        '{"schema_version":1,"operations":{},"unexpected":1}',
        '{"schema_version":1,"schema_version":1,"operations":{}}',
        '{"schema_version":1,"operations":{"timeout.connect_ms":{"op":"set","value":1,"value":2}}}',
        '{not-json',
    ];
    $sql = [];
    $this->db->listen(function ($event) use (&$sql): void {
        $sql[] = $event->sql;
    });
    foreach ($bad as $payload) {
        $command = new UpdatePolicyCommand($head, $this->tenant, PolicyScope::Tenant, 'acme', $payload, $this->actor(0));
        expect(fn () => $service->update($command))->toThrow(InvalidSettingPatch::class);
    }
    expect($sql)->toBe([]);
    expect($this->table('policy_heads')->count())->toBe(0)->and($this->table('policy_revisions')->count())->toBe(0)
        ->and($this->table('audit_events')->count())->toBe(0)->and($this->table('catalog_command_receipts')->count())->toBe(0);
});

it('descriptor ограничивает scopes permissions типы регистрацию и sensitive поля', function (): void {
    $factory = new SettingRegistryFactory($this->app);
    $registry = $factory->make(['approved_providers' => ['fixture' => FixtureSettingsProvider::class], 'providers' => ['fixture']]);
    expect($registry->descriptor('fixture.limit')->permission)->toBe('policy.fixture.edit');
    expect((new SettingRegistry(array_reverse($registry->all())))->fingerprint())->toBe($registry->fingerprint())
        ->and((new SettingRegistry(CoreSettings::descriptors()))->fingerprint())->not->toBe($registry->fingerprint());
    $setting = new SettingPatch(1, ['fixture.limit' => SettingChange::set(3)]);
    $registry->validate($setting, PolicyScope::Tenant);
    expect(fn () => $registry->validate($setting, PolicyScope::Panel))->toThrow(InvalidSettingPatch::class);
    expect(fn () => $factory->make(['approved_providers' => [], 'providers' => [FixtureSettingsProvider::class]]))->toThrow(InvalidSettingPatch::class);
    expect(fn () => $factory->make(['approved_providers' => ['fixture' => FixtureSettingsProvider::class], 'providers' => ['unknown']]))->toThrow(InvalidSettingPatch::class);
    expect(fn () => $factory->make(['approved_providers' => ['fixture' => stdClass::class], 'providers' => ['fixture']]))->toThrow(InvalidSettingPatch::class);
    expect(fn () => $factory->make(['approved_providers' => [], 'providers' => [static fn () => null]]))->toThrow(InvalidSettingPatch::class);
    expect(fn () => new SettingRegistry([...CoreSettings::descriptors(), ...CoreSettings::descriptors()]))->toThrow(InvalidSettingPatch::class);
    expect(fn () => new SettingDescriptor('proxy.password', SettingType::Integer, 1, false, [PolicyScope::Tenant], SettingMerge::Minimum, 1, 10, 'policy.edit'))->toThrow(InvalidSettingPatch::class);
    expect(fn () => new SettingDescriptor('fixture.nobounds', SettingType::Integer, 1, false, [PolicyScope::Tenant], SettingMerge::Minimum, null, null, 'policy.edit'))->toThrow(InvalidSettingPatch::class);
    $sensitive = new SettingDescriptor('fixture.private', SettingType::IdentifierList, null, true, [PolicyScope::Tenant], SettingMerge::Replace, null, null, 'policy.edit', sensitive: true);
    $restricted = new SettingRegistry([$sensitive]);
    expect(fn () => $restricted->validate(new SettingPatch(1, ['fixture.private' => SettingChange::set([])]), PolicyScope::Tenant))->toThrow(InvalidSettingPatch::class);
    $restricted->validate(new SettingPatch(1, ['fixture.private' => SettingChange::inherit()]), PolicyScope::Tenant);
});

it('общий размер revision проверяется до записи даже когда каждый отдельный patch допустим', function (): void {
    $this->commands();
    $service = $this->app->make(PolicyCommands::class);
    $head = Uuid::random();
    $values = array_map(static fn (int $index): string => str_repeat('a', 123).sprintf('%05d', $index), range(0, 255));
    $first = new UpdatePolicyCommand($head, $this->tenant, PolicyScope::Tenant, 'acme',
        policyPayload(['routes.denied' => ['op' => 'set', 'value' => $values]]), $this->actor(0, 'large-first'));
    $second = new UpdatePolicyCommand($head, $this->tenant, PolicyScope::Tenant, 'acme',
        policyPayload(['destinations.allowed' => ['op' => 'set', 'value' => $values]]), $this->actor(1, 'large-second'));
    expect(strlen($first->serializedPatch))->toBeLessThan(65536)->and(strlen($second->serializedPatch))->toBeLessThan(65536);
    expect($service->update($first)->revision)->toBe(1);
    expect(fn () => $service->update($second))->toThrow(InvalidSettingPatch::class);
    expect((int) $this->table('policy_heads')->value('revision'))->toBe(1)->and($this->table('policy_revisions')->count())->toBe(1)
        ->and($this->table('audit_events')->count())->toBe(1)->and($this->table('catalog_command_receipts')->count())->toBe(1);
});

it('проверяет trusted tenant actor и отдельные permissions перед записью и replay', function (): void {
    $this->commands();
    $service = $this->app->make(PolicyCommands::class);
    $head = Uuid::random();
    $payload = policyPayload(['timeout.connect_ms' => ['op' => 'set', 'value' => 1000]]);
    $command = new UpdatePolicyCommand($head, $this->tenant, PolicyScope::Tenant, 'acme', $payload, $this->actor(0, 'same'));
    $this->permissions(static fn ($actor, $tenant, $ability): bool => in_array($ability, ['policy.update', 'policy.scope.tenant.edit'], true));
    expect(fn () => $service->update($command))->toThrow(AuthorizationDenied::class);
    $scopeId = PolicyScope::Tenant->authorizationId($this->tenant, 'acme');
    expect($scopeId->value)->not->toBe(PolicyScope::Tenant->authorizationId(new TenantKey('other'), 'acme')->value);
    $this->permissions(static fn ($actor, $tenant, $ability, $resource): bool => $ability !== 'policy.scope.tenant.edit' || ! $resource->equals($scopeId));
    expect(fn () => $service->update($command))->toThrow(AuthorizationDenied::class);
    expect($this->table('policy_heads')->count())->toBe(0);
    $this->permissions(static fn (): bool => true);
    $service->update($command);
    $this->useTenant(new TenantKey('other'));
    expect(fn () => $service->update($command))->toThrow(AuthorizationDenied::class);
    $this->useTenant($this->tenant);
    $this->permissions(static fn (): bool => false);
    expect(fn () => $service->update($command))->toThrow(AuthorizationDenied::class);
    expect($this->table('policy_revisions')->count())->toBe(1);
    expect(fn () => new UpdatePolicyCommand($head, $this->tenant, PolicyScope::Global, 'system', $payload, $this->actor()))->toThrow(InvalidSettingPatch::class);
    expect(fn () => new UpdatePolicyCommand($head, $this->tenant, PolicyScope::Tenant, 'other', $payload, $this->actor()))->toThrow(InvalidSettingPatch::class);
    expect(fn () => $service->update(new UpdatePolicyCommand($head, $this->tenant, PolicyScope::Tenant, 'acme', $payload, new CatalogMutationActor('fixture-operator', 'reason'))))->toThrow(InvalidSettingPatch::class);
});

it('ошибки audit и stale revision откатывают head revision и receipt', function (): void {
    $this->commands();
    $service = $this->app->make(PolicyCommands::class);
    $head = Uuid::random();
    $payload = policyPayload(['retry.max_attempts' => ['op' => 'set', 'value' => 2]]);
    $first = new UpdatePolicyCommand($head, $this->tenant, PolicyScope::Tenant, 'acme', $payload, $this->actor(0, 'create'));
    $service->update($first);
    $badReason = hash('sha256', 'audit-failure');
    $this->db->statement("ALTER TABLE pxm_audit_events ADD CONSTRAINT fixture_policy_audit_fail CHECK (reason <> '{$badReason}')");
    $failed = new UpdatePolicyCommand($head, $this->tenant, PolicyScope::Tenant, 'acme', $payload, $this->actor(1, 'fail', 'audit-failure'));
    try {
        $service->update($failed);
        $this->fail('Audit failure must roll back the policy update.');
    } catch (PolicyStorageFailure $failure) {
        expect($failure->getPrevious())->toBeNull()->and($failure->getMessage())->toBe('Policy storage or audit failed.');
    }
    $failedCreate = new UpdatePolicyCommand(Uuid::random(), $this->tenant, PolicyScope::Panel, 'failed-panel', $payload, $this->actor(0, 'fail-create', 'audit-failure'));
    expect(fn () => $service->update($failedCreate))->toThrow(PolicyStorageFailure::class);
    $stale = new UpdatePolicyCommand($head, $this->tenant, PolicyScope::Tenant, 'acme', $payload, $this->actor(0, 'stale'));
    expect(fn () => $service->update($stale))->toThrow(PolicyConflict::class);
    $duplicateKey = new UpdatePolicyCommand($head, $this->tenant, PolicyScope::Tenant, 'acme', policyPayload([]), $this->actor(0, 'create'));
    expect(fn () => $service->update($duplicateKey))->toThrow(PolicyConflict::class);
    expect((int) $this->table('policy_heads')->value('revision'))->toBe(1)->and($this->table('policy_revisions')->count())->toBe(1)
        ->and($this->table('audit_events')->count())->toBe(1)->and($this->table('catalog_command_receipts')->count())->toBe(1);
    $this->rejectedSql(fn () => $this->table('audit_events')->delete());
    expect(fn () => $this->artisan('migrate:rollback', ['--step' => 12, '--force' => true]))->toThrow(LogicException::class, 'Policy audit history prevents rollback.');
    expect($this->table('audit_events')->count())->toBe(1)->and($this->table('policy_revisions')->count())->toBe(1);
});

it('две независимые транзакции с одной expected revision дают одну победу и typed conflict', function (): void {
    $this->commands();
    $head = Uuid::random();
    $service = $this->app->make(PolicyCommands::class);
    $service->update(new UpdatePolicyCommand($head, $this->tenant, PolicyScope::Tenant, 'acme', policyPayload([]), $this->actor(0, 'seed')));
    $monitor = $this->connectionPdo();
    $this->db->beginTransaction();
    $this->db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['proxyma:policy:acme:tenant:acme']);
    $script = dirname(__DIR__).'/Support/ConcurrentPolicyWorker.php';
    $one = new Process(['php', $script, $this->fixtureSchema, $head->value, 'one']);
    $two = new Process(['php', $script, $this->fixtureSchema, $head->value, 'two']);
    $one->setTimeout(15)->start();
    $two->setTimeout(15)->start();
    try {
        $limit = microtime(true) + 8;
        do {
            $statement = $monitor->prepare("SELECT count(*) FROM pg_stat_activity WHERE application_name LIKE ? AND wait_event_type = 'Lock'");
            $statement->execute(['proxyma-p21-'.$this->fixtureSchema.'-%']);
            $waiting = (int) $statement->fetchColumn();
            if ($waiting === 2) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $limit);
        if ($waiting !== 2) {
            throw new RuntimeException('Workers did not reach the lock: '.$one->getErrorOutput().' '.$two->getErrorOutput());
        }
        expect($waiting)->toBe(2);
    } finally {
        $this->db->rollBack();
    }
    $one->wait();
    $two->wait();
    expect($one->getExitCode())->toBe(0)->and($two->getExitCode())->toBe(0);
    $outcomes = [json_decode($one->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'], json_decode($two->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status']];
    sort($outcomes);
    expect($outcomes)->toBe(['conflict', 'updated'])->and((int) $this->table('policy_heads')->value('revision'))->toBe(2)
        ->and($this->table('policy_revisions')->count())->toBe(2)->and($this->table('audit_events')->where('entity_type', 'policy_heads')->count())->toBe(2);
});

it('config cache сериализует реестр без closures и builders', function (): void {
    $path = '/tmp/proxyma-p21-config-'.bin2hex(random_bytes(8)).'.php';
    $bootstrap = '/tmp/proxyma-p21-bootstrap-'.bin2hex(random_bytes(8));
    $previousBootstrap = $this->app->bootstrapPath();
    mkdir($bootstrap);
    $testbenchBootstrap = dirname(__DIR__, 4).'/vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
    file_put_contents($bootstrap.'/app.php', '<?php $app = require '.var_export($testbenchBootstrap, true).'; $app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $app->register(\\Proxyma\\Laravel\\ProxymaServiceProvider::class); $app["config"]->set("proxyma.settings", ["approved_providers" => ["fixture" => FixtureSettingsProvider::class], "providers" => ["fixture"]]); return $app;');
    $this->app->useBootstrapPath($bootstrap);
    $old = [getenv('APP_CONFIG_CACHE'), $_ENV['APP_CONFIG_CACHE'] ?? null, $_SERVER['APP_CONFIG_CACHE'] ?? null];
    putenv('APP_CONFIG_CACHE='.$path);
    $_ENV['APP_CONFIG_CACHE'] = $path;
    $_SERVER['APP_CONFIG_CACHE'] = $path;
    try {
        $this->artisan('config:cache')->assertExitCode(0);
        expect(is_file($path))->toBeTrue();
        $cache = require $path;
        $settings = $cache['proxyma']['settings'];
        expect($settings)->toBe(['approved_providers' => ['fixture' => FixtureSettingsProvider::class], 'providers' => ['fixture']]);
        $registry = (new SettingRegistryFactory($this->app))->make($settings);
        expect($registry->descriptor('timeout.connect_ms')->maximum)->toBe(120000)
            ->and($registry->descriptor('fixture.limit')->maximum)->toBe(5);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
        $this->app->useBootstrapPath($previousBootstrap);
        unlink($bootstrap.'/app.php');
        rmdir($bootstrap);
        $old[0] === false ? putenv('APP_CONFIG_CACHE') : putenv('APP_CONFIG_CACHE='.$old[0]);
        if ($old[1] === null) {
            unset($_ENV['APP_CONFIG_CACHE']);
        } else {
            $_ENV['APP_CONFIG_CACHE'] = $old[1];
        }
        if ($old[2] === null) {
            unset($_SERVER['APP_CONFIG_CACHE']);
        } else {
            $_SERVER['APP_CONFIG_CACHE'] = $old[2];
        }
    }
});
