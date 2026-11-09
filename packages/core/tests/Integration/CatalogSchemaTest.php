<?php

declare(strict_types=1);

use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;
use Proxyma\Application\Catalog\CatalogNotFound;
use Proxyma\Domain\Catalog\RetireCatalogEntityCommand;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\BudgetConstraint;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\OperationMutex;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Tests\Support\CatalogTestCase;

require_once __DIR__.'/../Support/CatalogTestCase.php';

uses(CatalogTestCase::class);

it('мигрирует независимый core дважды и откатывает только собственную схему', function (): void {
    $tables = array_map(static fn ($row) => $row->tablename, $this->db->select('SELECT tablename FROM pg_tables WHERE schemaname = current_schema()'));
    expect($tables)->toHaveCount(count(PackageSchema::tables()) + 1);
    foreach (PackageSchema::tables() as $name) {
        expect($tables)->toContain($name);
    }
    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    expect($this->db->table('migrations')->count())->toBe(4);
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_09_000001_create_pxm_authority_tables.php';
    $this->app['config']->set('proxyma.connection', 'configured-authority');
    expect($migration->getConnection())->toBe('configured-authority');
    $this->app['config']->set('proxyma.connection', 'pgsql');
    $parents = $this->db->select("SELECT confrelid::regclass::text AS parent, pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE contype = 'f' AND connamespace = current_schema()::regnamespace");
    foreach ($parents as $parent) {
        expect($parent->parent)->toStartWith('pxm_')->and($parent->definition)->toContain('tenant_key');
    }
    foreach ($this->db->select("SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE contype = 'u' AND connamespace = current_schema()::regnamespace") as $unique) {
        expect($unique->definition)->toContain('tenant_key');
    }
    $this->artisan('migrate:rollback', ['--force' => true])->assertExitCode(0);
    expect($this->db->select('SELECT tablename FROM pg_tables WHERE schemaname = current_schema()'))->toHaveCount(1);
    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    expect($this->table('endpoints')->count())->toBe(0);
});

it('изолирует uniqueness и FK арендаторов, проверяет route kind XOR', function (): void {
    $c = $this->provision();
    $base = ['tenant_key' => 'acme', 'route_key' => 'other', 'kind' => 'direct', 'endpoint_id' => null, 'egress_key' => 'nat:other'];
    $this->rejectedSql(fn () => $this->table('routes')->insert(array_replace($base, ['kind' => 'proxy'])));
    $this->rejectedSql(fn () => $this->table('routes')->insert(array_replace($base, ['kind' => 'invalid'])));
    $endpoint = Uuid::random();
    $this->table('endpoints')->insert(['id' => $endpoint->value, 'tenant_key' => 'foreign', 'protocol' => 'http', 'host' => 'proxy.test', 'port' => 8080]);
    $this->rejectedSql(fn () => $this->table('routes')->insert(array_replace($base, ['endpoint_id' => $endpoint->value])));
    $this->rejectedSql(fn () => $this->table('routes')->insert(array_replace($base, ['kind' => 'proxy', 'endpoint_id' => $endpoint->value])), '23503');
    $this->table('routes')->insert(array_replace($base, ['tenant_key' => 'foreign', 'route_key' => 'direct-main']));
    $this->rejectedSql(fn () => $this->table('routes')->insert(array_replace($base, ['route_key' => 'direct-main'])), '23505');
    $this->rejectedSql(fn () => $this->table('route_profile_members')->insert(['tenant_key' => 'foreign', 'profile_id' => $c['profile']->value, 'route_id' => $c['route']->value, 'position' => 0]), '23503');
    $this->rejectedSql(fn () => $this->table('targets')->insert(['tenant_key' => '', 'target_key' => 'x', 'canonical_origin' => 'https://example.test:443', 'destination_policy_key' => 'default']));
    $this->rejectedSql(fn () => $this->table('routes')->insert(array_replace($base, ['tenant_key' => null])), '23502');
    $this->rejectedSql(fn () => $this->table('routes')->insert(array_replace($base, ['route_key' => str_repeat('a', 129)])));
    $this->rejectedSql(fn () => $this->table('endpoints')->insert(['tenant_key' => 'acme', 'protocol' => 'http', 'host' => 'proxy.test', 'port' => 0]));
    $this->rejectedSql(fn () => $this->table('endpoints')->insert(['tenant_key' => 'acme', 'protocol' => 'http', 'host' => 'proxy.test', 'port' => 8080, 'credential_ref' => 'ref']));
    foreach (['https://example.test:0', 'https://example.test:99999', 'https://EXAMPLE.test:443', 'https://user@example.test:443'] as $badOrigin) {
        $this->rejectedSql(fn () => $this->table('targets')->insert(['tenant_key' => 'acme', 'target_key' => 'bad-origin', 'canonical_origin' => $badOrigin, 'destination_policy_key' => 'default']));
    }
});

it('фиксирует canonical scope keys с C collation и документированные query indexes', function (): void {
    foreach (['origin:a', 'origin:0', 'origin:-', 'origin:z'] as $key) {
        $this->table('budgets')->insert(['tenant_key' => 'acme', 'scope_key' => $key, 'emission_interval_us' => 1, 'burst' => 1, 'max_inflight' => 1]);
    }
    $keys = $this->table('budgets')->orderByRaw('scope_key COLLATE "C"')->pluck('scope_key')->all();
    $sorted = $keys;
    sort($sorted, SORT_STRING);
    expect($keys)->toBe($sorted);
    $collation = $this->db->selectOne("SELECT c.collname FROM pg_attribute a JOIN pg_collation c ON c.oid = a.attcollation WHERE a.attrelid = 'pxm_budgets'::regclass AND a.attname = 'scope_key'");
    expect($collation->collname)->toBe('C');
    foreach (['', 'Origin:a', 'origin:é', 'origin a', str_repeat('a', 256)] as $bad) {
        expect(fn () => new BudgetConstraint($bad, 1))->toThrow(InvalidArgumentException::class);
        $this->rejectedSql(fn () => $this->table('budgets')->insert(['tenant_key' => 'acme', 'scope_key' => $bad, 'emission_interval_us' => 1, 'burst' => 1, 'max_inflight' => 1]));
    }
    $indexes = $this->db->select('SELECT tablename, indexname, indexdef FROM pg_indexes WHERE schemaname = current_schema()');
    $names = array_column(array_map(static fn (object $row): array => (array) $row, $indexes), 'indexname');
    foreach (['pxm_demand_fifo_idx', 'pxm_demand_deadline_idx', 'pxm_operation_deadline_idx', 'pxm_permit_due_idx', 'pxm_permit_operation_idx',
        'pxm_attempt_unknown_idx', 'pxm_budget_lock_idx', 'pxm_reservation_budget_idx', 'pxm_route_health_probe_idx', 'pxm_profile_health_probe_idx',
        'pxm_target_health_probe_idx', 'pxm_route_target_health_probe_idx', 'pxm_route_target_target_idx', 'pxm_route_endpoint_idx', 'pxm_member_route_idx',
        'pxm_observation_attempt_idx', 'pxm_audit_entity_idx'] as $name) {
        expect($names)->toContain($name);
    }
    foreach ($indexes as $index) {
        expect(strlen($index->indexname))->toBeLessThanOrEqual(63);
        if ($index->indexname === 'pxm_budget_lock_idx') {
            expect($index->tablename)->toBe('pxm_budgets')->and($index->indexdef)->toContain('UNIQUE');
        }
    }
});

it('сохраняет referenced retirement и запрещает прямые изменения ревизий и immutable policy history', function (): void {
    $c = $this->provision();
    $operation = $this->operation($c);
    $this->rejectedSql(fn () => $this->table('targets')->where('id', $c['target']->value)->delete());
    $this->rejectedSql(fn () => $this->table('routes')->where('id', $c['route']->value)->delete());
    $this->rejectedSql(fn () => $this->table('targets')->where('id', $c['target']->value)->update(['destination_policy_key' => 'changed']));
    $this->rejectedSql(fn () => $this->table('targets')->where('id', $c['target']->value)->update(['revision' => 3]));
    $retired = $this->commands()->retireTarget(new RetireCatalogEntityCommand($c['target'], $this->tenant, $this->actor(1)));
    expect($retired->isRetired())->toBeTrue()->and($this->table('operations')->where('id', $operation->value)->exists())->toBeTrue();
    $this->rejectedSql(fn () => $this->table('targets')->where('id', $c['target']->value)->update(['retired_at' => null, 'revision' => 3]));
    $head = Uuid::random()->value;
    $rev = Uuid::random()->value;
    $this->table('policy_heads')->insert(['id' => $head, 'tenant_key' => 'acme', 'scope_key' => 'tenant:acme']);
    $this->table('policy_revisions')->insert(['id' => $rev, 'tenant_key' => 'acme', 'head_id' => $head, 'definition' => '{}']);
    $this->rejectedSql(fn () => $this->table('policy_revisions')->where('id', $rev)->update(['definition' => '{"allow":true}']));
    $this->rejectedSql(fn () => $this->table('policy_revisions')->where('id', $rev)->delete());
    $this->rejectedSql(fn () => $this->table('audit_events')->delete());
});

it('проверяет положительные costs, ownership, health и одноразовую идентичность permit claim', function (): void {
    $c = $this->provision();
    $operation = $this->operation($c);
    $this->rejectedSql(fn () => $this->table('operations')->where('id', $operation->value)->update(['owner_generation' => 0]));
    $this->rejectedSql(fn () => $this->table('operations')->where('id', $operation->value)->update(['max_physical_attempts' => 0]));
    $this->rejectedSql(fn () => $this->table('operations')->where('id', $operation->value)->update(['connect_timeout_ms' => 0]));
    $this->rejectedSql(fn () => $this->table('operations')->where('id', $operation->value)->update(['request_timeout_ms' => 1]));
    $this->rejectedSql(fn () => $this->table('route_health')->update(['health_epoch' => 0]));
    $this->rejectedSql(fn () => $this->table('route_health')->update(['probe_token' => Uuid::random()->value]));
    $demand = ['id' => Uuid::random()->value, 'tenant_key' => 'acme', 'operation_id' => $operation->value, 'profile_id' => $c['profile']->value, 'target_id' => $c['target']->value,
        'purpose' => 'normal', 'cost' => 1, 'deadline' => $this->db->raw("clock_timestamp() + interval '1 minute'")];
    $this->rejectedSql(fn () => $this->table('demands')->insert(array_replace($demand, ['cost' => 0])));
    $this->table('demands')->insert($demand);
    $permit = ['tenant_key' => 'acme', 'operation_id' => $operation->value, 'demand_id' => $demand['id'], 'route_id' => $c['route']->value, 'target_id' => $c['target']->value,
        'physical_attempt_id' => Uuid::random()->value, 'wire_fingerprint' => str_repeat('a', 64), 'cost' => 1,
        'not_before' => $this->db->raw('clock_timestamp()'), 'expires_at' => $this->db->raw("clock_timestamp() + interval '1 minute'")];
    $this->rejectedSql(fn () => $this->table('permits')->insert($permit + ['state' => 'authorized']));
    $this->table('permits')->insert($permit);
    $this->rejectedSql(fn () => $this->table('permits')->insert($permit), '23505');
    $this->rejectedSql(fn () => $this->table('permits')->update(['state' => 'completed', 'authorized_at' => $this->db->raw('clock_timestamp()'), 'claim_id' => Uuid::random()->value]));
    $claim = Uuid::random()->value;
    $this->table('permits')->update(['state' => 'authorized', 'authorized_at' => $this->db->raw('clock_timestamp()'), 'claim_id' => $claim]);
    $this->rejectedSql(fn () => $this->table('permits')->update(['claim_id' => Uuid::random()->value]));
    $this->rejectedSql(fn () => $this->table('permits')->update(['state' => 'reserved', 'authorized_at' => null, 'claim_id' => null]));
    $this->table('permits')->update(['state' => 'unknown_after_authorization']);
    $this->table('permits')->update(['state' => 'completed', 'completed_at' => $this->db->raw('clock_timestamp()')]);
    expect($this->table('permits')->value('claim_id'))->toBe($claim);
    $permitId = $this->table('permits')->value('id');
    $attempt = ['tenant_key' => 'acme', 'operation_id' => $operation->value, 'permit_id' => $permitId,
        'physical_attempt_id' => $permit['physical_attempt_id'], 'receipt_id' => Uuid::random()->value,
        'wire_fingerprint' => str_repeat('a', 64), 'status' => 'completed', 'authorized_at' => $this->db->raw('clock_timestamp()'),
        'completed_at' => $this->db->raw('clock_timestamp()'), 'snapshot' => '{}'];
    $this->table('attempts')->insert($attempt);
    $this->rejectedSql(fn () => $this->table('attempts')->insert($attempt), '23505');
    $observation = ['tenant_key' => 'acme', 'attempt_id' => $this->table('attempts')->value('id'), 'receipt_id' => $attempt['receipt_id'],
        'classification' => 'delivered', 'evidence' => '{}', 'observed_at' => $this->db->raw('clock_timestamp()')];
    $this->table('observations')->insert($observation);
    $this->rejectedSql(fn () => $this->table('observations')->insert($observation), '23505');
    $application = ['tenant_key' => 'acme', 'observation_id' => $this->table('observations')->value('id'), 'application_key' => 'health:route', 'health_epoch' => 1];
    $this->table('observation_applications')->insert($application);
    $this->rejectedSql(fn () => $this->table('observation_applications')->insert($application), '23505');
    $secondDemand = array_replace($demand, ['id' => Uuid::random()->value]);
    $this->table('demands')->insert($secondDemand);
    $secondPermit = array_replace($permit, ['physical_attempt_id' => Uuid::random()->value, 'demand_id' => $secondDemand['id'],
        'state' => 'authorized', 'claim_id' => $claim, 'authorized_at' => $this->db->raw('clock_timestamp()')]);
    $this->rejectedSql(fn () => $this->table('permits')->insert($secondPermit), '23505');
    $this->rejectedSql(fn () => $this->table('budgets')->insert(['tenant_key' => 'acme', 'scope_key' => 'origin:x', 'emission_interval_us' => 0, 'burst' => 1, 'max_inflight' => 1]));
    $budgetId = Uuid::random()->value;
    $this->table('budgets')->insert(['id' => $budgetId, 'tenant_key' => 'acme', 'scope_key' => 'origin:quota', 'emission_interval_us' => 1, 'burst' => 1, 'max_inflight' => 1]);
    $window = ['tenant_key' => 'acme', 'budget_id' => $budgetId, 'starts_at' => '2030-01-01T00:00:00Z', 'ends_at' => '2030-01-02T00:00:00Z', 'quota_limit' => 2];
    $this->rejectedSql(fn () => $this->table('quota_windows')->insert(array_replace($window, ['ends_at' => $window['starts_at']])));
    $this->rejectedSql(fn () => $this->table('quota_windows')->insert($window + ['reserved' => 2, 'spent' => 1]));
    $this->table('quota_windows')->insert($window);
    $this->rejectedSql(fn () => $this->table('quota_windows')->insert($window), '23505');
    $otherBudget = Uuid::random()->value;
    $this->table('budgets')->insert(['id' => $otherBudget, 'tenant_key' => 'acme', 'scope_key' => 'origin:other', 'emission_interval_us' => 1, 'burst' => 1, 'max_inflight' => 1]);
    $this->rejectedSql(fn () => $this->table('reservations')->insert(['tenant_key' => 'acme', 'permit_id' => $permitId, 'budget_id' => $otherBudget,
        'quota_window_id' => $this->table('quota_windows')->value('id'), 'cost' => 1, 'state' => 'reserved']), '23503');
});

it('operation root требует owning transaction и блокирует независимое соединение до network locks', function (): void {
    $c = $this->provision();
    $operation = $this->operation($c);
    $secondOperation = $this->operation($c);
    $mutex = new OperationMutex($this->db);
    expect(fn () => $mutex->lock($this->tenant, [$operation]))->toThrow(LogicException::class);
    $pdo = $this->connectionPdo();
    $other = new PostgresConnection($pdo, 'proxyma_test');
    $this->db->beginTransaction();
    $order = [];
    $this->db->listen(function ($query) use (&$order): void {
        if (str_contains($query->sql, 'pxm_operations') && str_contains($query->sql, 'for update')) {
            $order[] = $query->bindings[1];
        }
    });
    $mutex->lock($this->tenant, [$secondOperation, $operation, $secondOperation]);
    $expected = [$operation->value, $secondOperation->value];
    sort($expected, SORT_STRING);
    expect($order)->toBe($expected);
    $other->beginTransaction();
    $other->statement("SET LOCAL lock_timeout = '50ms'");
    try {
        (new OperationMutex($other))->lock($this->tenant, [$operation]);
        $this->fail('Concurrent operation root lock must wait.');
    } catch (QueryException $e) {
        expect($e->errorInfo[0])->toBe('55P03');
    } finally {
        $other->rollBack();
        $this->db->rollBack();
    }
    $other->transaction(fn () => (new OperationMutex($other))->lock($this->tenant, [$operation]));
    $this->db->transaction(function () use ($mutex, $operation): void {
        expect(fn () => $mutex->lock(new TenantKey('other'), [$operation]))->toThrow(CatalogNotFound::class);
    });
});
