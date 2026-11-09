<?php

declare(strict_types=1);

use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Catalog\CatalogInvalidMutation;
use Proxyma\Application\Catalog\CatalogNotFound;
use Proxyma\Contracts\Authorizer;
use Proxyma\Contracts\CatalogCommands;
use Proxyma\Contracts\CatalogQueries;
use Proxyma\Contracts\RouteAccessChecks;
use Proxyma\Contracts\SharedRouteCommands;
use Proxyma\Contracts\TenantContextResolver;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Catalog\CredentialRef;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Domain\Catalog\RetireCatalogEntityCommand;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Catalog\SetRouteEnabledCommand;
use Proxyma\Domain\Catalog\SharedRouteGrant;
use Proxyma\Domain\Catalog\ShareRouteCommand;
use Proxyma\Domain\Catalog\UpsertEndpointCommand;
use Proxyma\Domain\Catalog\UpsertRouteCommand;
use Proxyma\Domain\Catalog\UpsertRouteProfileCommand;
use Proxyma\Domain\Catalog\UpsertTargetCommand;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Security\DenyAuthorizer;
use Proxyma\Infrastructure\Security\UnconfiguredTenantContextResolver;
use Proxyma\Tests\Support\CatalogTestCase;

require_once __DIR__.'/../Support/CatalogTestCase.php';

uses(CatalogTestCase::class);

it('все query boundaries отвергают tenant из payload и отсутствие permissions до SQL', function (): void {
    $c = $this->provision();
    $queries = $this->app->make(CatalogQueries::class);
    $other = new TenantKey('foreign');
    $reads = [
        fn () => $queries->findEndpoint($other, Uuid::random()),
        fn () => $queries->findRoute($other, $c['route']),
        fn () => $queries->findProfile($other, $c['profile']),
        fn () => $queries->findTarget($other, $c['target']),
        fn () => $queries->routesForProfile($other, $c['profile']),
    ];
    $sql = [];
    $this->db->listen(function ($event) use (&$sql): void {
        $sql[] = $event->sql;
    });
    foreach ($reads as $read) {
        expect($read)->toThrow(AuthorizationDenied::class);
    }
    expect($sql)->toBe([]);
    $this->useTenant($other);
    expect($queries->findRoute($other, $c['route']))->toBeNull()
        ->and($queries->findProfile($other, $c['profile']))->toBeNull()
        ->and($queries->findTarget($other, $c['target']))->toBeNull()
        ->and($queries->routesForProfile($other, $c['profile']))->toBe([]);

    $this->useTenant($this->tenant);
    $this->permissions(static fn (): bool => false);
    $sql = [];
    foreach ([
        fn () => $queries->findEndpoint($this->tenant, Uuid::random()),
        fn () => $queries->findRoute($this->tenant, $c['route']),
        fn () => $queries->findProfile($this->tenant, $c['profile']),
        fn () => $queries->findTarget($this->tenant, $c['target']),
        fn () => $queries->routesForProfile($this->tenant, $c['profile']),
    ] as $read) {
        expect($read)->toThrow(AuthorizationDenied::class);
    }
    expect($sql)->toBe([]);
});

it('каждая mutation проверяет trusted tenant и actor, даже при полном разрешении adapter', function (): void {
    $c = $this->provision();
    $commands = $this->commands();
    $other = new TenantKey('foreign');
    $actor = $this->actor(1);
    $attempts = [
        fn () => $commands->upsertEndpoint(new UpsertEndpointCommand(Uuid::random(), $other, ProxyProtocol::Http, 'proxy.test', 8080, null, $this->actor())),
        fn () => $commands->retireEndpoint(new RetireCatalogEntityCommand(Uuid::random(), $other, $actor)),
        fn () => $commands->upsertRoute(new UpsertRouteCommand($c['route'], $other, 'direct', RouteKind::Direct, null, true, EgressIdentity::explicit('nat:other'), null, false, $actor)),
        fn () => $commands->setRouteEnabled(new SetRouteEnabledCommand($c['route'], $other, false, $actor)),
        fn () => $commands->upsertProfile(new UpsertRouteProfileCommand($c['profile'], $other, 'default', [], 'uniform_random', $actor)),
        fn () => $commands->upsertTarget(new UpsertTargetCommand($c['target'], $other, 'origin', CanonicalOrigin::of('https', 'example.test'), 'default', $actor)),
        fn () => $commands->retireTarget(new RetireCatalogEntityCommand($c['target'], $other, $actor)),
        fn () => $this->app->make(SharedRouteCommands::class)->shareRoute(new ShareRouteCommand(Uuid::random(), $other, $this->tenant, $c['route'], $this->actor())),
        fn () => $this->app->make(SharedRouteCommands::class)->revokeRouteGrant(new RetireCatalogEntityCommand(Uuid::random(), $other, $actor)),
        fn () => $commands->setRouteEnabled(new SetRouteEnabledCommand($c['route'], $this->tenant, false,
            new CatalogMutationActor(new ActorRef('operator', 'forged-actor'), 'reason', 1, 'forged'))),
    ];
    $sql = [];
    $this->db->listen(function ($event) use (&$sql): void {
        $sql[] = $event->sql;
    });
    foreach ($attempts as $attempt) {
        expect($attempt)->toThrow(AuthorizationDenied::class);
    }
    expect($sql)->toBe([]);
});

it('нет resolver или authorizer означает deny и не использует default system как authority', function (): void {
    $commands = $this->commands();
    $queries = $this->app->make(CatalogQueries::class);
    $command = new UpsertTargetCommand(Uuid::random(), $this->tenant, 'fixture', CanonicalOrigin::of('https', 'example.test'), 'default', $this->actor());
    $this->app->instance(TenantContextResolver::class, new UnconfiguredTenantContextResolver);
    expect(fn () => $commands->upsertTarget($command))->toThrow(AuthorizationDenied::class);
    expect(fn () => $queries->findTarget($this->tenant, $command->targetId))->toThrow(AuthorizationDenied::class);
    $this->useTenant($this->tenant);
    $this->app->instance(Authorizer::class, new DenyAuthorizer);
    expect(fn () => $commands->upsertTarget($command))->toThrow(AuthorizationDenied::class);
    expect(fn () => $queries->findTarget($this->tenant, $command->targetId))->toThrow(AuthorizationDenied::class);
    expect($this->table('targets')->count())->toBe(0)->and($this->table('audit_events')->count())->toBe(0);
});

it('profile enumeration и связывание требуют permission для каждого candidate', function (): void {
    $c = $this->provision();
    $commands = $this->app->make(CatalogCommands::class);
    $queries = $this->app->make(CatalogQueries::class);
    $this->permissions(static fn ($actor, $tenant, $ability): bool => $ability !== 'catalog.route.select');
    expect(fn () => $queries->routesForProfile($this->tenant, $c['profile']))->toThrow(AuthorizationDenied::class);
    expect(fn () => $commands->upsertProfile(new UpsertRouteProfileCommand($c['profile'], $this->tenant, 'default', [$c['route']], 'uniform_random', $this->actor(1))))->toThrow(AuthorizationDenied::class);
    expect((int) $this->table('route_profiles')->value('revision'))->toBe(1);
});

it('shared flag не выдаёт wildcard, grant ограничен owner consumer route и актуальной revision', function (): void {
    $c = $this->provision();
    $commands = $this->commands();
    $queries = $this->app->make(CatalogQueries::class);
    $shared = $this->app->make(SharedRouteCommands::class);
    $consumer = new TenantKey('consumer');
    $third = new TenantKey('third');
    $commands->upsertRoute(new UpsertRouteCommand($c['route'], $this->tenant, 'direct-main', RouteKind::Direct, null, true, EgressIdentity::explicit('nat:fixture'), null, true, $this->actor(1)));
    $raw = new Route($c['route'], $this->tenant, 'direct-main', RouteKind::Direct, null, true, EgressIdentity::explicit('nat:fixture'), sharedGrant: true);
    expect($raw->isAccessibleBy($consumer))->toBeFalse();
    expect(fn () => new SharedRouteGrant(Uuid::random(), $this->tenant, $this->tenant, $c['route']))->toThrow(InvalidArgumentException::class);
    $this->useTenant($consumer);
    expect($queries->findRoute($consumer, $c['route']))->toBeNull();
    $profileId = Uuid::random();
    $profile = new UpsertRouteProfileCommand($profileId, $consumer, 'shared-profile', [$c['route']], 'uniform_random', $this->actor());
    expect(fn () => $commands->upsertProfile($profile))->toThrow(CatalogNotFound::class);
    $this->useTenant($this->tenant);
    $share = new ShareRouteCommand(Uuid::random(), $this->tenant, $consumer, $c['route'], $this->actor());
    $grant = $shared->shareRoute($share);
    expect($shared->shareRoute($share)->id->value)->toBe($grant->id->value);
    $this->useTenant($third);
    expect($queries->findRoute($third, $c['route']))->toBeNull();
    $this->useTenant($consumer);
    $commands->upsertProfile($profile);
    $prepared = $queries->routesForProfile($consumer, $profileId)[0];
    expect($prepared->accessGrant->revision)->toBe(1)->and($prepared->isAccessibleBy($consumer))->toBeTrue()
        ->and($prepared->isAccessibleBy($third))->toBeFalse();
    $checks = $this->app->make(RouteAccessChecks::class);
    expect(fn () => $checks->assertCurrent($prepared))->toThrow(CatalogInvalidMutation::class);
    $this->db->transaction(fn () => $checks->assertCurrent($prepared));

    // Binding с тем же opaque subject id из другого tenant остаётся независимым.
    foreach ([$consumer, $third] as $tenant) {
        $head = Uuid::random();
        $this->table('policy_heads')->insert(['id' => $head->value, 'tenant_key' => $tenant->value, 'scope_key' => 'subject:42']);
        $this->table('bindings')->insert(['tenant_key' => $tenant->value, 'subject_type' => 'process', 'subject_id' => '42',
            'head_id' => $head->value, 'profile_id' => $tenant->equals($consumer) ? $profileId->value : null]);
    }
    $this->useTenant($this->tenant);
    $revoke = new RetireCatalogEntityCommand($grant->id, $this->tenant, $this->actor(1));
    expect($shared->revokeRouteGrant($revoke)->revision)->toBe(2);
    expect($shared->revokeRouteGrant($revoke)->revision)->toBe(2);
    expect((int) $this->table('bindings')->where('tenant_key', $consumer->value)->value('revision'))->toBe(2)
        ->and((int) $this->table('bindings')->where('tenant_key', $third->value)->value('revision'))->toBe(1);
    $this->useTenant($consumer);
    expect($queries->findRoute($consumer, $c['route']))->toBeNull();
    expect(fn () => $queries->routesForProfile($consumer, $profileId))->toThrow(AuthorizationDenied::class);
    expect(fn () => $this->db->transaction(fn () => $checks->assertCurrent($prepared)))->toThrow(AuthorizationDenied::class);
    $this->useTenant($this->tenant);
    $shared->shareRoute(new ShareRouteCommand(Uuid::random(), $this->tenant, $consumer, $c['route'], $this->actor()));
    $this->useTenant($consumer);
    expect(fn () => $queries->routesForProfile($consumer, $profileId))->toThrow(AuthorizationDenied::class);
    expect(fn () => $this->db->transaction(fn () => $checks->assertCurrent($prepared)))->toThrow(AuthorizationDenied::class);
    $commands->upsertProfile(new UpsertRouteProfileCommand($profileId, $consumer, 'shared-profile', [$c['route']], 'uniform_random', $this->actor(1)));
    expect($queries->routesForProfile($consumer, $profileId))->toHaveCount(1);
});

it('raw SQL не подменяет owner consumer или grant membership и не удаляет историю grant', function (): void {
    $c = $this->provision();
    $consumer = new TenantKey('consumer');
    $third = new TenantKey('third');
    $shared = $this->app->make(SharedRouteCommands::class);
    $grant = $shared->shareRoute(new ShareRouteCommand(Uuid::random(), $this->tenant, $consumer, $c['route'], $this->actor()));
    $this->rejectedSql(fn () => $this->table('shared_route_grants')->insert(['tenant_key' => 'third', 'consumer_tenant_key' => 'consumer', 'route_id' => $c['route']->value]), '23503');
    $this->rejectedSql(fn () => $this->table('shared_route_grants')->where('id', $grant->id->value)->update(['consumer_tenant_key' => 'third', 'revision' => 2]));
    $this->rejectedSql(fn () => $this->table('shared_route_grants')->where('id', $grant->id->value)->delete());
    $this->useTenant($third);
    $profileId = Uuid::random();
    $this->commands()->upsertProfile(new UpsertRouteProfileCommand($profileId, $third, 'third-profile', [], 'uniform_random', $this->actor()));
    $pivot = ['tenant_key' => 'third', 'route_owner_tenant_key' => 'acme', 'profile_id' => $profileId->value,
        'route_id' => $c['route']->value, 'shared_grant_id' => $grant->id->value, 'position' => 0];
    $this->rejectedSql(fn () => $this->table('route_profile_members')->insert($pivot));
    $this->useTenant($this->tenant);
    $shared->revokeRouteGrant(new RetireCatalogEntityCommand($grant->id, $this->tenant, $this->actor(1)));
    $this->useTenant($consumer);
    $consumerProfile = Uuid::random();
    $this->commands()->upsertProfile(new UpsertRouteProfileCommand($consumerProfile, $consumer, 'consumer-profile', [], 'uniform_random', $this->actor()));
    $this->rejectedSql(fn () => $this->table('route_profile_members')->insert(array_replace($pivot, ['tenant_key' => 'consumer', 'profile_id' => $consumerProfile->value])));
});

it('один service в worker заново получает tenant actor permissions, одинаковый subject id не пересекается', function (): void {
    $commands = $this->commands();
    $queries = $this->app->make(CatalogQueries::class);
    $other = new TenantKey('foreign');
    $a = new SubjectRef($this->tenant, 'process', '42');
    $b = new SubjectRef($other, 'process', '42');
    expect($a->equals($b))->toBeFalse()->and($a->key())->not->toBe($b->key());
    $first = new UpsertTargetCommand(Uuid::random(), $a->tenant, 'same-key', CanonicalOrigin::of('https', 'first.test'), 'default', $this->actor(0, 'same-key'));
    $commands->upsertTarget($first);
    $this->useTenant($other);
    $second = new UpsertTargetCommand(Uuid::random(), $b->tenant, 'same-key', CanonicalOrigin::of('https', 'second.test'), 'default', $this->actor(0, 'same-key'));
    $commands->upsertTarget($second);
    expect(fn () => $commands->upsertTarget($first))->toThrow(AuthorizationDenied::class);
    expect(fn () => $queries->findTarget($a->tenant, $first->targetId))->toThrow(AuthorizationDenied::class);
    expect($queries->findTarget($b->tenant, $first->targetId))->toBeNull()
        ->and($queries->findTarget($b->tenant, $second->targetId)->origin->host)->toBe('second.test');
    $this->useTenant($this->tenant);
    expect($queries->findTarget($a->tenant, $first->targetId)->origin->host)->toBe('first.test');
    $this->permissions(static fn (): bool => false);
    expect(fn () => $commands->upsertTarget($first))->toThrow(AuthorizationDenied::class);
    expect(fn () => $queries->findTarget($a->tenant, $first->targetId))->toThrow(AuthorizationDenied::class);
    $this->useTenant($this->tenant, new ActorRef('operator', 'next-job-actor'));
    expect(fn () => $commands->upsertTarget($first))->toThrow(AuthorizationDenied::class);
});

it('shared read не раскрывает endpoint credentials и prepared route устаревает при rotation', function (): void {
    $commands = $this->commands();
    $endpoint = Uuid::random();
    $routeId = Uuid::random();
    $commands->upsertEndpoint(new UpsertEndpointCommand($endpoint, $this->tenant, ProxyProtocol::Http, 'proxy.test', 8080, new CredentialRef('opaque:one'), $this->actor()));
    $commands->upsertRoute(new UpsertRouteCommand($routeId, $this->tenant, 'proxy', RouteKind::Proxy, $endpoint, true, EgressIdentity::explicit('nat:proxy'), null, false, $this->actor()));
    $consumer = new TenantKey('consumer');
    $this->app->make(SharedRouteCommands::class)->shareRoute(new ShareRouteCommand(Uuid::random(), $this->tenant, $consumer, $routeId, $this->actor()));
    $queries = $this->app->make(CatalogQueries::class);
    $checks = $this->app->make(RouteAccessChecks::class);
    $this->useTenant($consumer);
    $prepared = $queries->findRoute($consumer, $routeId);
    expect($queries->findEndpoint($consumer, $endpoint))->toBeNull();
    $this->db->transaction(fn () => $checks->assertCurrent($prepared));
    $this->useTenant($this->tenant);
    $commands->upsertEndpoint(new UpsertEndpointCommand($endpoint, $this->tenant, ProxyProtocol::Http, 'proxy.test', 8080, new CredentialRef('opaque:two', 2), $this->actor(1)));
    $this->useTenant($consumer);
    expect(fn () => $this->db->transaction(fn () => $checks->assertCurrent($prepared)))->toThrow(AuthorizationDenied::class);
});

it('grant lock держится до SQL claim, смена grant revision и disable инвалидируют prepared route', function (): void {
    $c = $this->provision();
    $consumer = new TenantKey('consumer');
    $grant = $this->app->make(SharedRouteCommands::class)->shareRoute(new ShareRouteCommand(Uuid::random(), $this->tenant, $consumer, $c['route'], $this->actor()));
    $queries = $this->app->make(CatalogQueries::class);
    $checks = $this->app->make(RouteAccessChecks::class);
    $this->useTenant($consumer);
    $prepared = $queries->findRoute($consumer, $c['route']);
    $pdo = $this->connectionPdo();
    $this->db->beginTransaction();
    try {
        $checks->assertCurrent($prepared);
        $pdo->beginTransaction();
        $pdo->exec("SET LOCAL lock_timeout = '50ms'");
        try {
            $pdo->prepare('UPDATE pxm_shared_route_grants SET revision = revision + 1 WHERE id = ?')->execute([$grant->id->value]);
            $this->fail('Prepared grant lock must block a concurrent revision update.');
        } catch (PDOException $failure) {
            expect($failure->getCode())->toBe('55P03');
        } finally {
            $pdo->rollBack();
        }
    } finally {
        $this->db->rollBack();
    }
    $pdo->prepare('UPDATE pxm_shared_route_grants SET revision = revision + 1 WHERE id = ?')->execute([$grant->id->value]);
    expect($queries->findRoute($consumer, $c['route'])->accessGrant->revision)->toBe(2);
    expect(fn () => $this->db->transaction(fn () => $checks->assertCurrent($prepared)))->toThrow(AuthorizationDenied::class);
    $prepared = $queries->findRoute($consumer, $c['route']);
    $this->useTenant($this->tenant);
    $this->commands()->setRouteEnabled(new SetRouteEnabledCommand($c['route'], $this->tenant, false, $this->actor(1)));
    $this->useTenant($consumer);
    expect(fn () => $this->db->transaction(fn () => $checks->assertCurrent($prepared)))->toThrow(AuthorizationDenied::class);
});
