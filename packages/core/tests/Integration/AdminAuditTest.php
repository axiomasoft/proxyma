<?php

declare(strict_types=1);

use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Catalog\CatalogConflict;
use Proxyma\Application\Catalog\CatalogInvalidMutation;
use Proxyma\Application\Catalog\CatalogStorageFailure;
use Proxyma\Contracts\CatalogQueries;
use Proxyma\Contracts\SharedRouteCommands;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Catalog\CredentialRef;
use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Domain\Catalog\RetireCatalogEntityCommand;
use Proxyma\Domain\Catalog\ShareRouteCommand;
use Proxyma\Domain\Catalog\UpsertEndpointCommand;
use Proxyma\Domain\Catalog\UpsertRouteProfileCommand;
use Proxyma\Domain\Catalog\UpsertTargetCommand;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\BudgetConstraint;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Audit\CatalogAudit;
use Proxyma\Tests\Support\CatalogTestCase;

require_once __DIR__.'/../Support/CatalogTestCase.php';

uses(CatalogTestCase::class);

it('actor reason revision и idempotency обязательны, отказ не оставляет audit или authority', function (): void {
    $commands = $this->commands();
    expect(fn () => new CatalogMutationActor('', 'reason'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new CatalogMutationActor('operator', ' '))->toThrow(InvalidArgumentException::class);
    foreach ([new CatalogMutationActor('fixture-operator', 'reason'), new CatalogMutationActor('fixture-operator', 'reason', 0)] as $actor) {
        expect(fn () => $commands->upsertTarget(new UpsertTargetCommand(Uuid::random(), $this->tenant, 'fixture', CanonicalOrigin::of('https', 'example.test'), 'default', $actor)))->toThrow(CatalogInvalidMutation::class);
    }
    expect($this->table('targets')->count())->toBe(0)->and($this->table('audit_events')->count())->toBe(0)
        ->and($this->table('catalog_command_receipts')->count())->toBe(0);
});

it('audit содержит только имена patch полей и revisions, без значений secrets FQCN и моделей', function (): void {
    $commands = $this->commands();
    $actor = new ActorRef('operator', 'SECRET-ACTOR');
    $this->useTenant($this->tenant, $actor);
    $id = Uuid::random();
    $first = new UpsertEndpointCommand($id, $this->tenant, ProxyProtocol::Http, 'sensitive-proxy.test', 8080,
        new CredentialRef('secret:SECRET-REFERENCE'), new CatalogMutationActor($actor, 'SECRET-REASON Proxyma\\Sensitive\\Model', 0, 'SECRET-KEY'));
    $commands->upsertEndpoint($first);
    $commands->upsertEndpoint(new UpsertEndpointCommand($id, $this->tenant, ProxyProtocol::Http, 'sensitive-proxy.test', 8080,
        new CredentialRef('secret:ROTATED-REFERENCE', 2), new CatalogMutationActor($actor, 'rotate', 1, 'rotate-key')));
    $events = $this->table('audit_events')->where('entity_id', $id->value)->orderBy('revision')->get();
    $created = json_decode($events[0]->result, true, flags: JSON_THROW_ON_ERROR);
    $updated = json_decode($events[1]->result, true, flags: JSON_THROW_ON_ERROR);
    expect($created['before_fields'])->toBe([])->and($created['after_fields'])->toContain('credential_ref', 'credential_version', 'host', 'port', 'protocol')
        ->and($updated)->toEqual(['before_fields' => ['credential_ref', 'credential_version'], 'after_fields' => ['credential_ref', 'credential_version']]);
    expect((int) $events[1]->previous_revision)->toBe(1)->and((int) $events[1]->revision)->toBe(2)
        ->and($events[0]->actor_id)->toBe(hash('sha256', 'SECRET-ACTOR'));
    $dump = json_encode($events->all(), JSON_THROW_ON_ERROR);
    foreach (['SECRET-ACTOR', 'SECRET-REASON', 'SECRET-KEY', 'SECRET-REFERENCE', 'ROTATED-REFERENCE', 'sensitive-proxy.test', 'Proxyma'] as $forbidden) {
        expect($dump)->not->toContain($forbidden);
    }
    $receipt = $this->table('catalog_command_receipts')->where('idempotency_key', CatalogAudit::key('SECRET-KEY'))->first();
    $projection = json_decode($receipt->result, true, flags: JSON_THROW_ON_ERROR);
    expect($projection)->not->toHaveKey('created_at')->not->toHaveKey('updated_at')->not->toHaveKey('actor')
        ->and($projection['credential_ref'])->toBe('secret:SECRET-REFERENCE');
});

it('duplicate command стабилен после новой revision, конфликт и отозванные permissions не дают replay', function (): void {
    $commands = $this->commands();
    $id = Uuid::random();
    $first = new UpsertTargetCommand($id, $this->tenant, 'fixture', CanonicalOrigin::of('https', 'old.test'), 'default', $this->actor(0, 'once'));
    $commands->upsertTarget($first);
    $commands->upsertTarget(new UpsertTargetCommand($id, $this->tenant, 'fixture', CanonicalOrigin::of('https', 'new.test'), 'default', $this->actor(1)));
    $replay = $commands->upsertTarget($first);
    expect($replay->revision)->toBe(1)->and($replay->origin->host)->toBe('old.test')
        ->and($this->table('audit_events')->where('event_kind', 'mutation')->count())->toBe(2)
        ->and($this->table('catalog_command_receipts')->count())->toBe(2);
    expect(fn () => $commands->upsertTarget(new UpsertTargetCommand($id, $this->tenant, 'changed', $first->origin, 'default', $first->actor)))->toThrow(CatalogConflict::class);
    $this->permissions(static fn (): bool => false);
    expect(fn () => $commands->upsertTarget($first))->toThrow(AuthorizationDenied::class);
    expect($this->table('catalog_command_receipts')->count())->toBe(2);
});

it('audit failure откатывает catalog membership budgets grant и binding revisions, исключение не содержит SQL values', function (): void {
    $c = $this->provision();
    $commands = $this->commands();
    $before = $this->table('audit_events')->count();
    $this->db->statement("ALTER TABLE pxm_audit_events ADD CONSTRAINT fixture_audit_failure CHECK (reason <> '".hash('sha256', 'fail-audit')."')");
    $bad = new UpsertRouteProfileCommand($c['profile'], $this->tenant, 'secret-profile-name', [], 'uniform_random', $this->actor(1, reason: 'fail-audit'), [new BudgetConstraint('origin:secret', 100)]);
    try {
        $commands->upsertProfile($bad);
        $this->fail('Audit failure must abort administration.');
    } catch (CatalogStorageFailure $failure) {
        expect($failure->getMessage())->toBe('Catalog storage or audit failed.')->and($failure->getPrevious())->toBeNull();
    }
    expect((int) $this->table('route_profiles')->value('revision'))->toBe(1)
        ->and($this->table('route_profile_members')->count())->toBe(1)->and($this->table('budgets')->count())->toBe(0)
        ->and($this->table('audit_events')->count())->toBe($before);
    $shared = $this->app->make(SharedRouteCommands::class);
    $consumer = new TenantKey('consumer');
    $grant = $shared->shareRoute(new ShareRouteCommand(Uuid::random(), $this->tenant, $consumer, $c['route'], $this->actor()));
    $this->useTenant($consumer);
    $profileId = Uuid::random();
    $commands->upsertProfile(new UpsertRouteProfileCommand($profileId, $consumer, 'consumer-profile', [$c['route']], 'uniform_random', $this->actor()));
    $head = Uuid::random();
    $this->table('policy_heads')->insert(['id' => $head->value, 'tenant_key' => 'consumer', 'scope_key' => 'subject:42']);
    $this->table('bindings')->insert(['tenant_key' => 'consumer', 'subject_type' => 'process', 'subject_id' => '42', 'head_id' => $head->value, 'profile_id' => $profileId->value]);
    $this->useTenant($this->tenant);
    expect(fn () => $shared->revokeRouteGrant(new RetireCatalogEntityCommand($grant->id, $this->tenant, $this->actor(1, reason: 'fail-audit'))))->toThrow(CatalogStorageFailure::class);
    expect((int) $this->table('shared_route_grants')->value('revision'))->toBe(1)->and($this->table('shared_route_grants')->value('retired_at'))->toBeNull()
        ->and((int) $this->table('bindings')->value('revision'))->toBe(1);
    $this->rejectedSql(fn () => $this->table('audit_events')->update(['reason' => 'tamper']));
    $this->rejectedSql(fn () => $this->table('catalog_command_receipts')->delete());
});

it('read audit проверяет actor tenant revision и не возвращает данные при отказе журнала', function (): void {
    $c = $this->provision();
    $queries = $this->app->make(CatalogQueries::class);
    $queries->findTarget($this->tenant, $c['target']);
    $event = $this->table('audit_events')->where('event_kind', 'read')->first();
    expect($event->tenant_key)->toBe('acme')->and($event->ability)->toBe('catalog.target.read')
        ->and((int) $event->previous_revision)->toBe(1)->and((int) $event->revision)->toBe(1)
        ->and(json_decode($event->result, true))->toEqual(['before_fields' => [], 'after_fields' => []]);
    $this->db->statement("ALTER TABLE pxm_audit_events ADD CONSTRAINT fixture_read_failure CHECK (event_kind <> 'read') NOT VALID");
    expect(fn () => $queries->findTarget($this->tenant, $c['target']))->toThrow(CatalogStorageFailure::class);
    expect($this->table('audit_events')->where('event_kind', 'read')->count())->toBe(1);
});

it('upgrade установленной P1.3 схемы сохраняет catalog и replay, удаляя snapshots из audit', function (): void {
    $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertExitCode(0);
    $id = Uuid::random();
    $auditId = Uuid::random();
    $legacy = ['id' => $id->value, 'tenant_key' => 'acme', 'target_key' => 'legacy', 'canonical_origin' => 'https://legacy.test:443',
        'destination_policy_key' => 'default', 'revision' => 1, 'retired_at' => null];
    $this->table('targets')->insert($legacy);
    $this->table('audit_events')->insert(['id' => $auditId->value, 'tenant_key' => 'acme', 'actor_type' => 'operator', 'actor_id' => 'legacy-actor',
        'reason' => 'legacy-secret-reason', 'ability' => 'catalog.target.upsert', 'entity_type' => 'targets', 'entity_id' => $id->value,
        'previous_revision' => 0, 'revision' => 1, 'idempotency_key' => 'legacy-secret-key', 'command_hash' => str_repeat('a', 64),
        'result' => json_encode($legacy, JSON_THROW_ON_ERROR)]);
    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    expect($this->table('targets')->value('id'))->toBe($id->value);
    $receipt = $this->table('catalog_command_receipts')->first();
    expect($receipt->idempotency_key)->toBe(hash('sha256', 'legacy-secret-key'))
        ->and(json_decode($receipt->result, true))->toEqual($legacy);
    $event = $this->table('audit_events')->first();
    expect($event->reason)->toBe(hash('sha256', 'legacy-secret-reason'))
        ->and($event->result)->not->toContain('legacy.test', 'canonical_origin')
        ->and($event->actor_id)->toBe(hash('sha256', 'legacy-actor'));
    $this->rejectedSql(fn () => $this->table('audit_events')->delete());
});
