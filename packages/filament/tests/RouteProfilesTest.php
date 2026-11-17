<?php

declare(strict_types=1);

use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Catalog\CatalogNotFound;
use Proxyma\Contracts\Catalog\CatalogCommands;
use Proxyma\Contracts\Catalog\CatalogQueries;
use Proxyma\Contracts\Catalog\SharedRouteCommands;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\RetireCatalogEntityCommand;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Catalog\ShareRouteCommand;
use Proxyma\Domain\Catalog\UpsertRouteCommand;
use Proxyma\Domain\Catalog\UpsertRouteProfileCommand;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Filament\Actions\SaveCatalog;
use Proxyma\Filament\CatalogBrowser;
use Proxyma\Filament\Resources\Pages\RouteProfiles;
use Proxyma\Filament\Resources\Pages\Routes;
use Proxyma\Filament\Tests\Support\WithFilament;
use Proxyma\Tests\Support\CatalogTestCase;

require_once __DIR__.'/../../core/tests/Support/CatalogTestCase.php';
require_once __DIR__.'/Support/WithFilament.php';

uses(CatalogTestCase::class, WithFilament::class)->group('pg');

beforeEach(function (): void {
    $this->prepareFilament();
});

it('пустой профиль явно запрещает отправку и не добавляет direct автоматически', function (): void {
    $catalog = $this->provision();
    $page = Livewire::test(RouteProfiles::class)->assertSee('Пустой профиль запрещает отправку')
        ->set('data.profile_key', 'empty')->set('data.reason', 'Запрет отправки')->call('save')->assertHasNoErrors()->assertSet('revision', 1);
    $queries = app(CatalogQueries::class);
    $profile = $queries->findProfile($this->tenant, new Uuid($page->get('recordId')));
    $route = $queries->findRoute($this->tenant, $catalog['route']);
    expect($profile->isEmpty())->toBeTrue()->and($profile->allowsRoute($route, true))->toBeFalse()
        ->and($queries->routesForProfile($this->tenant, $profile->id))->toBe([]);
    $page->set('data.member_route_ids', [$route->id->value])->set('data.reason', 'Явный direct')->call('save')->assertHasNoErrors();
    $profile = $queries->findProfile($this->tenant, $profile->id);
    expect($profile->memberRouteIds)->toHaveCount(1)->and($profile->allowsRoute($route))->toBeFalse()
        ->and($profile->allowsRoute($route, true))->toBeTrue();
});

it('изменение membership заменяет точный состав, а очистка сохраняет empty deny', function (): void {
    $catalog = $this->provision();
    $second = app(CatalogCommands::class)->upsertRoute(new UpsertRouteCommand(Uuid::random(), $this->tenant, 'direct-second', RouteKind::Direct,
        null, true, EgressIdentity::explicit('nat:second'), null, false, $this->actor()));
    $page = Livewire::test(RouteProfiles::class)->call('edit', $catalog['profile']->value)
        ->set('data.member_route_ids', [$second->id->value])->set('data.reason', 'Замена состава')
        ->call('save')->assertHasNoErrors()->assertSet('revision', 2);
    $profile = app(CatalogQueries::class)->findProfile($this->tenant, $catalog['profile']);
    expect($profile->containsRoute($second->id))->toBeTrue()->and($profile->containsRoute($catalog['route']))->toBeFalse()
        ->and($this->table('route_profile_members')->where('profile_id', $catalog['profile']->value)->pluck('route_id')->all())->toBe([$second->id->value]);
    $page->set('data.member_route_ids', [])->set('data.reason', 'Очистка')->call('save')->assertHasNoErrors()->assertSet('revision', 3);
    expect(app(CatalogQueries::class)->routesForProfile($this->tenant, $catalog['profile']))->toBe([]);
    $audit = $this->table('audit_events')->where('entity_id', $catalog['profile']->value)->where('event_kind', 'mutation')->orderBy('revision')->get();
    expect($audit)->toHaveCount(3)->and((int) $audit->last()->previous_revision)->toBe(2)
        ->and(json_decode($audit->last()->result, true)['after_fields'])->toContain('member_route_ids');
});

it('устаревшая форма membership получает conflict без last-write-wins', function (): void {
    $catalog = $this->provision();
    $first = Livewire::test(RouteProfiles::class)->call('edit', $catalog['profile']->value);
    $stale = Livewire::test(RouteProfiles::class)->call('edit', $catalog['profile']->value);
    $first->set('data.member_route_ids', [])->set('data.reason', 'Запрет')->call('save')->assertHasNoErrors();
    $stale->set('data.reason', 'Старый состав')->call('save')->assertHasErrors(['data'])->assertSet('revision', 1)
        ->assertSee('Перезагрузите запись');
    $profile = app(CatalogQueries::class)->findProfile($this->tenant, $catalog['profile']);
    expect($profile->revision)->toBe(2)->and($profile->memberRouteIds)->toBe([]);
});

it('не позволяет подменить server-owned record ID или revision через Livewire', function (string $property, mixed $value): void {
    $page = Livewire::test(RouteProfiles::class);
    expect(fn () => $page->set($property, $value))->toThrow(CannotUpdateLockedPropertyException::class);
    expect($this->table('route_profiles')->count())->toBe(0);
})->with(['record ID' => ['recordId', '00000000-0000-4000-8000-000000000001'], 'revision' => ['revision', 42]]);

it('отвергает forged foreign profile ID при загрузке и прямом сохранении', function (): void {
    $foreign = new TenantKey('foreign');
    $this->useTenant($foreign);
    $profile = app(CatalogCommands::class)->upsertProfile(new UpsertRouteProfileCommand(Uuid::random(), $foreign, 'foreign-profile', [], 'uniform_random', $this->actor()));
    $this->useTenant($this->tenant);
    Livewire::test(RouteProfiles::class)->assertDontSee('foreign-profile')->call('edit', $profile->id->value)->assertNotFound();
    expect(fn () => app(SaveCatalog::class)->save('profile', $profile->id->value, 1,
        ['profile_key' => 'forged', 'member_route_ids' => [], 'strategy_key' => 'uniform_random', 'reason' => 'Подмена']))->toThrow(CatalogNotFound::class);
    expect($this->table('route_profiles')->sole()->profile_key)->toBe('foreign-profile')
        ->and((int) $this->table('route_profiles')->sole()->revision)->toBe(1);
});

it('чужой route ID в membership не создаёт частичную запись или audit', function (): void {
    $catalog = $this->provision();
    $foreign = new TenantKey('foreign');
    $this->useTenant($foreign);
    $route = app(CatalogCommands::class)->upsertRoute(new UpsertRouteCommand(Uuid::random(), $foreign, 'foreign-direct', RouteKind::Direct,
        null, true, EgressIdentity::explicit('nat:foreign'), null, false, $this->actor()));
    $this->useTenant($this->tenant);
    $before = $this->table('audit_events')->where('event_kind', 'mutation')->count();
    expect(app(CatalogBrowser::class)->options('route'))->not->toHaveKey($route->id->value);
    expect(fn () => app(SaveCatalog::class)->save('profile', $catalog['profile']->value, 1,
        ['profile_key' => 'default', 'member_route_ids' => [$route->id->value], 'strategy_key' => 'uniform_random', 'reason' => 'Подмена']))->toThrow(CatalogNotFound::class);
    expect($this->table('route_profile_members')->where('profile_id', $catalog['profile']->value)->pluck('route_id')->all())->toBe([$catalog['route']->value])
        ->and((int) $this->table('route_profiles')->where('id', $catalog['profile']->value)->sole()->revision)->toBe(1)
        ->and($this->table('audit_events')->where('event_kind', 'mutation')->count())->toBe($before);
});

it('проверяет permission выбора route даже при разрешённом profile upsert', function (): void {
    $catalog = $this->provision();
    $this->permissions(fn ($actor, $tenant, string $ability): bool => $ability !== 'catalog.route.select');
    expect(app(CatalogBrowser::class)->options('route'))->toBe([]);
    expect(fn () => app(SaveCatalog::class)->save('profile', $catalog['profile']->value, 1,
        ['profile_key' => 'default', 'member_route_ids' => [$catalog['route']->value], 'strategy_key' => 'uniform_random', 'reason' => 'Выбор']))->toThrow(AuthorizationDenied::class);
    expect((int) $this->table('route_profiles')->sole()->revision)->toBe(1);
});

it('shared route виден только по живому explicit grant и исчезает после отзыва', function (): void {
    $owner = new TenantKey('owner');
    $this->useTenant($owner);
    $route = app(CatalogCommands::class)->upsertRoute(new UpsertRouteCommand(Uuid::random(), $owner, 'shared-direct', RouteKind::Direct,
        null, true, EgressIdentity::explicit('nat:shared'), null, false, $this->actor()));
    $grant = app(SharedRouteCommands::class)->shareRoute(new ShareRouteCommand(Uuid::random(), $owner, $this->tenant, $route->id, $this->actor()));
    $this->useTenant($this->tenant);
    expect(app(CatalogBrowser::class)->options('route'))->toHaveKey($route->id->value);
    $page = Livewire::test(RouteProfiles::class)->set('data.profile_key', 'shared-profile')
        ->set('data.member_route_ids', [$route->id->value])->set('data.reason', 'Разрешённый shared route')
        ->call('save')->assertHasNoErrors();
    $profileId = new Uuid($page->get('recordId'));
    expect(app(CatalogQueries::class)->routesForProfile($this->tenant, $profileId))->toHaveCount(1);
    $this->useTenant($owner);
    app(SharedRouteCommands::class)->revokeRouteGrant(new RetireCatalogEntityCommand($grant->id, $owner, $this->actor(1)));
    $this->useTenant($this->tenant);
    expect(app(CatalogBrowser::class)->options('route'))->toBe([]);
    expect(fn () => app(SaveCatalog::class)->save('profile', $profileId->value, 1,
        ['profile_key' => 'shared-profile', 'member_route_ids' => [$route->id->value], 'strategy_key' => 'uniform_random', 'reason' => 'Отозвано']))->toThrow(CatalogNotFound::class);
    expect((int) $this->table('route_profiles')->sole()->revision)->toBe(1);
});

it('создаёт proxy и explicit direct routes через commands и не превращает proxy в fallback', function (): void {
    $endpoint = $this->endpoint();
    $page = Livewire::test(Routes::class)->set('data.route_key', 'proxy-main')->set('data.endpoint_id', $endpoint->id->value)
        ->set('data.egress_key', 'pool:fixture')->set('data.reason', 'Прокси')->call('save')->assertHasNoErrors()->assertSet('revision', 1);
    $routeId = new Uuid($page->get('recordId'));
    expect(app(CatalogQueries::class)->findRoute($this->tenant, $routeId)->kind)->toBe(RouteKind::Proxy);
    $page->set('data.kind', 'direct')->set('data.reason', 'Без неявного fallback')->call('save')->assertHasErrors(['data.endpoint_id']);
    expect(app(CatalogQueries::class)->findRoute($this->tenant, $routeId)->kind)->toBe(RouteKind::Proxy);
    $page->set('data.endpoint_id', null)->set('data.reason', 'Явный direct')->call('save')->assertHasNoErrors()->assertSet('revision', 2);
    expect(app(CatalogQueries::class)->findRoute($this->tenant, $routeId)->kind)->toBe(RouteKind::Direct);
});

it('не сохраняет неизвестную стратегию из формы', function (): void {
    Livewire::test(RouteProfiles::class)->set('data.profile_key', 'unknown-strategy')->set('data.strategy_key', 'unknown')
        ->set('data.reason', 'Неверная стратегия')->call('save')->assertHasErrors(['data.strategy_key']);
    expect($this->table('route_profiles')->count())->toBe(0);
});
