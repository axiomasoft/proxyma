<?php

declare(strict_types=1);

use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Catalog\CatalogConflict;
use Proxyma\Application\Catalog\CatalogInvalidMutation;
use Proxyma\Application\Catalog\CatalogNotFound;
use Proxyma\Application\Catalog\CatalogStorageFailure;
use Proxyma\Contracts\CatalogCommands;
use Proxyma\Contracts\CatalogQueries;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Catalog\CredentialRef;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Domain\Catalog\RetireCatalogEntityCommand;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Catalog\SetRouteEnabledCommand;
use Proxyma\Domain\Catalog\UpsertEndpointCommand;
use Proxyma\Domain\Catalog\UpsertRouteCommand;
use Proxyma\Domain\Catalog\UpsertRouteProfileCommand;
use Proxyma\Domain\Catalog\UpsertTargetCommand;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\BudgetConstraint;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Eloquent\EndpointRecord;
use Proxyma\Laravel\ProxymaServiceProvider;
use Proxyma\Tests\Support\CatalogTestCase;

require_once __DIR__.'/../Support/CatalogTestCase.php';

uses(CatalogTestCase::class);

it('не пишет без явного Authorizer и без обязательных revision/idempotency metadata', function (): void {
    expect(fn () => new ActorRef('Invalid type', 'operator'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ActorRef('operator', ''))->toThrow(InvalidArgumentException::class);
    expect(fn () => new CatalogMutationActor('operator', 'reason', -1, 'key'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new CatalogMutationActor('operator', 'reason', 0, "bad\nkey"))->toThrow(InvalidArgumentException::class);
    $unconfigured = $this->app->make(CatalogCommands::class);
    $denied = new UpsertTargetCommand(Uuid::random(), $this->tenant, 'denied', CanonicalOrigin::of('https', 'example.test'), 'default', $this->actor());
    expect(fn () => $unconfigured->upsertTarget($denied))->toThrow(AuthorizationDenied::class);
    $commands = $this->commands();
    $id = Uuid::random();
    $command = new UpsertTargetCommand($id, $this->tenant, 'fixture', CanonicalOrigin::of('https', 'example.test'), 'default', new CatalogMutationActor('fixture-operator', 'old constructor'));
    expect(fn () => $commands->upsertTarget($command))->toThrow(CatalogInvalidMutation::class);
    expect($this->table('targets')->count())->toBe(0)->and($this->table('audit_events')->where('event_kind', 'mutation')->count())->toBe(0);
    $valid = new UpsertTargetCommand($id, $this->tenant, 'fixture', $command->origin, 'default', $this->actor());
    $badBudget = new UpsertTargetCommand($id, $this->tenant, 'fixture', $command->origin, 'default', $this->actor(), ['untyped']);
    expect(fn () => $commands->upsertTarget($badBudget))->toThrow(CatalogInvalidMutation::class);
    expect(fn () => $this->commands(false)->upsertTarget($valid))->toThrow(RuntimeException::class, 'fixture authorization denied');
    expect($this->table('targets')->count())->toBe(0)->and($this->table('target_health')->count())->toBe(0)->and($this->table('audit_events')->where('event_kind', 'mutation')->count())->toBe(0);
    $this->db->transaction(function () use ($commands, $valid): void {
        expect(fn () => $commands->upsertTarget($valid))->toThrow(CatalogInvalidMutation::class);
    });
});

it('expected revision конфликт не меняет каталог, audit, membership или budget', function (): void {
    $c = $this->provision();
    $commands = $this->commands();
    $beforeAudit = $this->table('audit_events')->where('event_kind', 'mutation')->count();
    $bad = new UpsertRouteProfileCommand($c['profile'], $this->tenant, 'changed', [], 'uniform_random', $this->actor(0), [new BudgetConstraint('origin:fixture', 10)]);
    expect(fn () => $commands->upsertProfile($bad))->toThrow(CatalogConflict::class);
    $profile = $this->app->make(CatalogQueries::class)->findProfile($this->tenant, $c['profile']);
    expect($profile->revision)->toBe(1)->and($profile->profileKey)->toBe('default')->and($profile->memberRouteIds[0]->value)->toBe($c['route']->value)
        ->and($this->table('audit_events')->where('event_kind', 'mutation')->count())->toBe($beforeAudit)->and($this->table('budgets')->count())->toBe(0);
});

it('audit и authority revision атомарны даже при отказе последней записи transaction', function (): void {
    $c = $this->provision();
    $before = $this->table('audit_events')->where('event_kind', 'mutation')->count();
    $reasonHash = hash('sha256', 'fail-audit');
    $this->db->statement("ALTER TABLE pxm_audit_events ADD CONSTRAINT fixture_audit_fail CHECK (reason <> '{$reasonHash}')");
    $command = new UpsertTargetCommand($c['target'], $this->tenant, 'changed', CanonicalOrigin::of('https', 'new.test'), 'default', $this->actor(1, reason: 'fail-audit'), [new BudgetConstraint('origin:new', 100)]);
    expect(fn () => $this->commands()->upsertTarget($command))->toThrow(CatalogStorageFailure::class);
    $target = $this->app->make(CatalogQueries::class)->findTarget($this->tenant, $c['target']);
    expect($target->revision)->toBe(1)->and($target->targetKey)->toBe('fixture-origin')->and($target->origin->host)->toBe('example.test')
        ->and($this->table('audit_events')->where('event_kind', 'mutation')->count())->toBe($before)->and($this->table('budgets')->count())->toBe(0);
    $success = new UpsertTargetCommand($c['target'], $this->tenant, 'changed', $command->origin, 'default', $this->actor(1));
    expect($this->commands()->upsertTarget($success)->revision)->toBe(2);
    $audit = $this->table('audit_events')->where('event_kind', 'mutation')->where('entity_id', $c['target']->value)->orderByDesc('revision')->first();
    expect((int) $audit->previous_revision)->toBe(1)->and((int) $audit->revision)->toBe(2)->and($audit->actor_type)->toBe('operator')->and($audit->reason)->toBe(hash('sha256', 'fixture change'));
});

it('повтор команды возвращает исходный результат, иной payload и повтор без прав отклоняются', function (): void {
    $commands = $this->commands();
    $id = Uuid::random();
    $actor = $this->actor(0, 'create-once');
    $command = new UpsertTargetCommand($id, $this->tenant, 'fixture', CanonicalOrigin::of('https', 'example.test'), 'default', $actor);
    $commands->upsertTarget($command);
    $commands->upsertTarget(new UpsertTargetCommand($id, $this->tenant, 'fixture', CanonicalOrigin::of('https', 'new.test'), 'default', $this->actor(1)));
    expect($commands->upsertTarget($command)->revision)->toBe(1)->and($this->table('targets')->where('id', $id->value)->value('revision'))->toBe(2)
        ->and($this->table('audit_events')->where('event_kind', 'mutation')->count())->toBe(2);
    $different = new UpsertTargetCommand($id, $this->tenant, 'different', $command->origin, 'default', $actor);
    expect(fn () => $commands->upsertTarget($different))->toThrow(CatalogConflict::class);
    expect(fn () => $this->commands(false)->upsertTarget($command))->toThrow(RuntimeException::class, 'fixture authorization denied');
    expect($this->table('audit_events')->where('event_kind', 'mutation')->count())->toBe(2);
});

it('provision создаёт все health пары независимо от порядка каталога и сохраняет runtime debt', function (): void {
    $commands = $this->commands();
    $target = Uuid::random();
    $budget = new BudgetConstraint('origin:fixture', 100, 2, 3, true);
    $commands->upsertTarget(new UpsertTargetCommand($target, $this->tenant, 'first', CanonicalOrigin::of('https', 'example.test'), 'default', $this->actor(), [$budget]));
    $route = Uuid::random();
    $routeCommand = new UpsertRouteCommand($route, $this->tenant, 'direct', RouteKind::Direct, null, true, EgressIdentity::explicit('nat:test'), null, false, $this->actor(), [$budget]);
    $commands->upsertRoute($routeCommand);
    $profile = Uuid::random();
    $commands->upsertProfile(new UpsertRouteProfileCommand($profile, $this->tenant, 'default', [$route, $route], 'uniform_random', $this->actor(), [$budget]));
    expect($this->table('profile_health')->count())->toBe(1)->and($this->table('route_health')->count())->toBe(1)
        ->and($this->table('target_health')->count())->toBe(1)->and($this->table('route_target_health')->count())->toBe(1)
        ->and($this->table('route_profile_members')->count())->toBe(1);
    $this->table('budgets')->update(['tat' => '2030-01-01T00:00:00Z', 'send_tat' => '2030-01-01T00:00:01Z', 'inflight' => 2]);
    foreach (['profile_health', 'route_health', 'target_health', 'route_target_health'] as $health) {
        $this->table($health)->update(['consecutive_failures' => 5, 'health_epoch' => 7, 'state' => 'open']);
    }
    $oldBudget = $this->table('budgets')->first();
    $oldHealth = $this->table('route_target_health')->first();
    $commands->upsertRoute($routeCommand);
    $updated = new BudgetConstraint('origin:fixture', 200, 1, 1, false);
    $commands->upsertTarget(new UpsertTargetCommand($target, $this->tenant, 'first', CanonicalOrigin::of('https', 'example.test'), 'default', $this->actor(1), [$updated]));
    $newBudget = $this->table('budgets')->first();
    $newHealth = $this->table('route_target_health')->first();
    expect($newBudget->id)->toBe($oldBudget->id)->and($newBudget->tat)->toBe($oldBudget->tat)->and($newBudget->send_tat)->toBe($oldBudget->send_tat)
        ->and((int) $newBudget->inflight)->toBe(2)->and((int) $newBudget->revision)->toBe(2)->and((int) $newBudget->emission_interval_us)->toBe(200)
        ->and($newHealth)->toEqual($oldHealth)->and($this->table('route_target_health')->count())->toBe(1);
    expect((bool) $newBudget->mandatory)->toBeTrue();
    foreach (['profile_health', 'route_health', 'target_health'] as $health) {
        expect((int) $this->table($health)->value('health_epoch'))->toBe(7)->and((int) $this->table($health)->value('consecutive_failures'))->toBe(5);
    }
    $commands->upsertProfile(new UpsertRouteProfileCommand($profile, $this->tenant, 'default', [], 'uniform_random', $this->actor(1)));
    expect($this->app->make(CatalogQueries::class)->findProfile($this->tenant, $profile)->isEmpty())->toBeTrue()
        ->and($this->table('route_target_health')->count())->toBe(1);
});

it('rotation, disable и retirement меняют только собственные revisions и сохраняют ссылки', function (): void {
    $commands = $this->commands();
    $endpoint = Uuid::random();
    $commands->upsertEndpoint(new UpsertEndpointCommand($endpoint, $this->tenant, ProxyProtocol::Http, 'PROXY.test.', 8080, new CredentialRef('secret:one'), $this->actor()));
    $route = Uuid::random();
    $commands->upsertRoute(new UpsertRouteCommand($route, $this->tenant, 'proxy', RouteKind::Proxy, $endpoint, true, EgressIdentity::conservativePool('fixture'), null, false, $this->actor()));
    $rotated = $commands->upsertEndpoint(new UpsertEndpointCommand($endpoint, $this->tenant, ProxyProtocol::Http, 'proxy.test', 8080, new CredentialRef('secret:two', 2), $this->actor(1)));
    expect($rotated->revision)->toBe(2)->and($rotated->credentialVersion())->toBe(2);
    $queries = $this->app->make(CatalogQueries::class);
    expect(fn () => $queries->findEndpoint(new TenantKey('other'), $endpoint))->toThrow(AuthorizationDenied::class);
    expect($queries->findRoute($this->tenant, $route)->revision)->toBe(1)->and($queries->findRoute($this->tenant, $route)->credentialVersion)->toBe(2);
    $bad = new UpsertEndpointCommand($endpoint, $this->tenant, ProxyProtocol::Http, 'proxy.test', 8080, new CredentialRef('secret:three', 2), $this->actor(2));
    expect(fn () => $commands->upsertEndpoint($bad))->toThrow(CatalogInvalidMutation::class);
    expect($commands->setRouteEnabled(new SetRouteEnabledCommand($route, $this->tenant, false, $this->actor(1)))->revision)->toBe(2);
    $retired = $commands->retireEndpoint(new RetireCatalogEntityCommand($endpoint, $this->tenant, $this->actor(2)));
    expect($retired->revision)->toBe(3)->and($retired->isRetired())->toBeTrue()->and($queries->findRoute($this->tenant, $route)->endpointId->value)->toBe($endpoint->value);
    expect(fn () => $commands->setRouteEnabled(new SetRouteEnabledCommand($route, $this->tenant, true, $this->actor(2))))->toThrow(CatalogNotFound::class);
    $revive = new UpsertEndpointCommand($endpoint, $this->tenant, ProxyProtocol::Http, 'proxy.test', 8080, new CredentialRef('secret:four', 4), $this->actor(3));
    expect(fn () => $commands->upsertEndpoint($revive))->toThrow(CatalogInvalidMutation::class);
});

it('queries и membership не принимают чужой tenant, shared flag не даёт wildcard доступ', function (): void {
    $c = $this->provision();
    $commands = $this->commands();
    $other = new TenantKey('foreign');
    $queries = $this->app->make(CatalogQueries::class);
    $this->useTenant($other);
    expect($queries->findRoute($other, $c['route']))->toBeNull()->and($queries->findProfile($other, $c['profile']))->toBeNull()
        ->and($queries->findTarget($other, $c['target']))->toBeNull()->and($queries->routesForProfile($other, $c['profile']))->toBe([]);
    $cross = new UpsertRouteProfileCommand(Uuid::random(), $other, 'default', [$c['route']], 'uniform_random', $this->actor());
    expect(fn () => $commands->upsertProfile($cross))->toThrow(CatalogNotFound::class);
    $this->useTenant($this->tenant);
    $commands->upsertRoute(new UpsertRouteCommand($c['route'], $this->tenant, 'direct-main', RouteKind::Direct, null, true, EgressIdentity::explicit('nat:fixture'), null, true, $this->actor(1)));
    $this->useTenant($other);
    expect($queries->findRoute($other, $c['route']))->toBeNull();
    expect(fn () => $commands->upsertProfile($cross))->toThrow(CatalogNotFound::class);
    $this->useTenant($this->tenant);
    expect($queries->routesForProfile($this->tenant, $c['profile']))->toHaveCount(1)->and($this->table('route_profiles')->count())->toBe(1);
});

it('provision пересекает duplicate constraints и берёт budget locks по canonical ключам', function (): void {
    $c = $this->provision();
    $order = [];
    $this->db->listen(function ($query) use (&$order): void {
        if (str_contains($query->sql, 'pxm_budgets') && str_contains($query->sql, 'for update')) {
            $order[] = $query->bindings[1];
        }
    });
    $profile = $this->commands()->upsertProfile(new UpsertRouteProfileCommand($c['profile'], $this->tenant, 'default', [$c['route']], 'uniform_random', $this->actor(1), [
        new BudgetConstraint('z:scope', 1, 10, 10),
        new BudgetConstraint('a:scope', 1, 10, 10),
        new BudgetConstraint('a:scope', 20, 1, 2, true),
    ]));
    expect($profile->revision)->toBe(2)->and($order)->toBe(['a:scope', 'z:scope']);
    $budget = $this->table('budgets')->where('scope_key', 'a:scope')->first();
    expect((int) $budget->emission_interval_us)->toBe(20)->and((int) $budget->burst)->toBe(1)
        ->and((int) $budget->max_inflight)->toBe(2)->and((bool) $budget->mandatory)->toBeTrue();
});

it('bootstrap не делает SQL, миграция имеет один autoload путь, ORM не мутирует authority', function (): void {
    $queries = [];
    $this->db->listen(function ($event) use (&$queries): void {
        $queries[] = $event->sql;
    });
    $provider = new ProxymaServiceProvider($this->app);
    $provider->register();
    $provider->boot();
    expect($queries)->toBe([]);
    $paths = $this->app['migrator']->paths();
    $expected = dirname(__DIR__, 2).'/database/migrations';
    $normalized = array_map(static fn (string $path): string => realpath($path), $paths);
    expect(count(array_filter($normalized, static fn (string $path): bool => $path === realpath($expected))))->toBe(1);
    $published = ProxymaServiceProvider::pathsToPublish(ProxymaServiceProvider::class);
    foreach (array_keys($published) as $source) {
        expect($source)->not->toContain('migrations');
    }
    expect(fn () => (new EndpointRecord)->save())->toThrow(LogicException::class);
    expect(fn () => (new EndpointRecord)->delete())->toThrow(LogicException::class);
    expect($this->table('endpoints')->count())->toBe(0);
});
