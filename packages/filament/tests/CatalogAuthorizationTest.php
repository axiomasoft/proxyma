<?php

declare(strict_types=1);

use Filament\Contracts\Plugin;
use Filament\Panel;
use Livewire\Livewire;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Catalog\RotateCredentials;
use Proxyma\Contracts\Catalog\CatalogCommands;
use Proxyma\Contracts\Catalog\CatalogQueries;
use Proxyma\Contracts\Security\SecretResolver;
use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Domain\Catalog\UpsertEndpointCommand;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Filament\CatalogBrowser;
use Proxyma\Filament\ProxymaPlugin;
use Proxyma\Filament\Resources\Pages\Endpoints;
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

it('регистрирует настоящий Filament 5 plugin без SQL и оставляет core независимым', function (): void {
    $sql = [];
    $this->db->listen(function ($query) use (&$sql): void {
        $sql[] = $query->sql;
    });
    $plugin = ProxymaPlugin::make();
    $panel = Panel::make()->id('second')->plugin($plugin);
    $plugin->boot($panel);
    expect($plugin)->toBeInstanceOf(Plugin::class)->and($plugin->getId())->toBe('proxyma')
        ->and($panel->getPages())->toContain(Endpoints::class, Routes::class, RouteProfiles::class)
        ->and($sql)->toBe([]);
    $core = json_decode(file_get_contents(__DIR__.'/../../core/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    expect(array_filter(array_keys($core['require']), fn (int|string $name): bool => str_starts_with($name, 'filament/')))->toBe([]);
});

it('создаёт endpoint через command с trusted ActorRef, причиной, revision и audit', function (): void {
    $page = Livewire::test(Endpoints::class)->set('data.host', 'proxy.example.test')->set('data.port', 8080)
        ->set('data.reason', 'Добавлен тестовый узел')->set('data.tenant_key', 'foreign')->call('save')->assertHasNoErrors()->assertSet('revision', 1);
    $row = $this->table('endpoints')->sole();
    expect($row->tenant_key)->toBe('acme')->and($row->host)->toBe('proxy.example.test')
        ->and($row->id)->toBe($page->get('recordId'));
    $audit = $this->table('audit_events')->where('event_kind', 'mutation')->sole();
    expect($audit->ability)->toBe('catalog.endpoint.upsert')->and($audit->actor_type)->toBe('operator')
        ->and($audit->actor_id)->toBe(hash('sha256', 'fixture-operator'))->and((int) $audit->previous_revision)->toBe(0)
        ->and((int) $audit->revision)->toBe(1)->and($audit->reason)->toBe(hash('sha256', 'Добавлен тестовый узел'));
});

it('прямой Livewire save отвергается при скрытой кнопке для каждого каталога', function (string $pageClass): void {
    $this->permissions(fn ($actor, $tenant, string $ability): bool => ! str_ends_with($ability, '.upsert'));
    Livewire::test($pageClass)->assertDontSee('Сохранить')->call('save')->assertForbidden();
    expect($this->table('endpoints')->count())->toBe(0)->and($this->table('routes')->count())->toBe(0)
        ->and($this->table('route_profiles')->count())->toBe(0)
        ->and($this->table('audit_events')->where('event_kind', 'mutation')->count())->toBe(0);
})->with([Endpoints::class, Routes::class, RouteProfiles::class]);

it('повторяет permission на уже открытой форме после отзыва прав', function (): void {
    $endpoint = $this->endpoint();
    $page = Livewire::test(Endpoints::class)->call('edit', $endpoint->id->value)->set('data.reason', 'Запрещено');
    $this->permissions(fn ($actor, $tenant, string $ability): bool => $ability !== 'catalog.endpoint.upsert');
    $page->call('save')->assertForbidden();
    expect((int) $this->table('endpoints')->sole()->revision)->toBe(1);
});

it('не показывает чужие endpoints и запрещает загрузку по подставленному ID', function (): void {
    $foreign = new TenantKey('foreign');
    $this->useTenant($foreign);
    $endpoint = $this->app->make(CatalogCommands::class)->upsertEndpoint(new UpsertEndpointCommand(
        Uuid::random(), $foreign, ProxyProtocol::Http, 'foreign.example.test', 8080, null, $this->actor()));
    $this->useTenant($this->tenant);
    Livewire::test(Endpoints::class)->assertDontSee('foreign.example.test')->call('edit', $endpoint->id->value)->assertNotFound();
    expect(app(CatalogBrowser::class)->options('endpoint'))->toBe([]);
});

it('secret ввод write-only, маска и пустое поле не заменяют credentials', function (): void {
    $endpoint = $this->endpoint();
    $page = Livewire::test(Endpoints::class)->call('edit', $endpoint->id->value)
        ->set('credentialData.username', 'secret-user')->set('credentialData.password', 'private-fixture-password')
        ->set('credentialData.reason', 'Ротация')->call('rotateCredentials')->assertHasNoErrors()->assertSet('revision', 2)
        ->assertSet('credentialData', [])->assertDontSee('private-fixture-password')->assertDontSee('secret-user');
    $current = app(CatalogQueries::class)->findEndpoint($this->tenant, $endpoint->id);
    $reference = $current->credential;
    expect(app(SecretResolver::class)->resolve($reference, $this->tenant)->revealSecret())->toBe('private-fixture-password');
    $page->call('edit', $endpoint->id->value)->assertSet('credentialData', [])->assertDontSee('private-fixture-password')
        ->set('data.host', 'changed.example.test')->set('data.reason', 'Смена адреса')->call('save')->assertHasNoErrors()->assertSet('revision', 3);
    expect(app(CatalogQueries::class)->findEndpoint($this->tenant, $endpoint->id)->credential->equals($reference))->toBeTrue();
    foreach (['••••••••', '********', ''] as $masked) {
        $page->set('credentialData.username', 'ignored')->set('credentialData.password', $masked)->set('credentialData.reason', 'Маска')
            ->call('rotateCredentials')->assertHasErrors(['credentialData.password'])->assertSet('credentialData', []);
    }
    expect((int) $this->table('endpoints')->sole()->revision)->toBe(3)
        ->and(app(SecretResolver::class)->resolve($reference, $this->tenant)->revealSecret())->toBe('private-fixture-password');
    $audit = json_encode($this->table('audit_events')->get()->all());
    expect($audit)->not->toContain('private-fixture-password', 'secret-user');
});

it('запрещает прямой вызов rotateCredentials без отдельного permission', function (): void {
    $endpoint = $this->endpoint();
    $this->permissions(fn ($actor, $tenant, string $ability): bool => $ability !== 'catalog.endpoint.rotate-credentials');
    Livewire::test(Endpoints::class)->call('edit', $endpoint->id->value)->assertDontSee('Заменить credentials')
        ->set('credentialData.password', 'forbidden-password')->set('credentialData.reason', 'Запрещено')
        ->call('rotateCredentials')->assertForbidden();
    expect($this->table('encrypted_secrets')->count())->toBe(0)->and((int) $this->table('endpoints')->sole()->revision)->toBe(1);
});

it('устаревшая форма endpoint и ротация не перезаписывают новое состояние', function (): void {
    $endpoint = $this->endpoint();
    $page = Livewire::test(Endpoints::class)->call('edit', $endpoint->id->value);
    app(RotateCredentials::class)->handle($endpoint->id, $this->tenant, 'new-user', 'new-password', $this->actor(1));
    $page->set('data.host', 'stale.example.test')->set('data.reason', 'Устарело')->call('save')->assertHasErrors(['data']);
    $page->set('credentialData.password', 'stale-password')->set('credentialData.reason', 'Устарело')
        ->call('rotateCredentials')->assertHasErrors(['credentialData'])->assertSet('credentialData', []);
    $current = app(CatalogQueries::class)->findEndpoint($this->tenant, $endpoint->id);
    expect($current->revision)->toBe(2)->and($current->host)->toBe('proxy.example.test')
        ->and(app(SecretResolver::class)->resolve($current->credential, $this->tenant)->revealSecret())->toBe('new-password');
});

it('фильтрует permissions каждой строки и использует bounded pagination', function (): void {
    $commands = $this->commands();
    $ids = [];
    for ($i = 0; $i < 26; $i++) {
        $ids[] = $commands->upsertEndpoint(new UpsertEndpointCommand(Uuid::random(), $this->tenant,
            ProxyProtocol::Http, "proxy{$i}.example.test", 8080, null, $this->actor()))->id->value;
    }
    $browser = app(CatalogBrowser::class);
    expect($browser->page('endpoint')->items())->toHaveCount(25)->and($browser->page('endpoint')->hasMorePages())->toBeTrue()
        ->and($browser->page('endpoint', 2)->items())->toHaveCount(1);
    $this->permissions(fn ($actor, $tenant, string $ability, $id): bool => $ability !== 'catalog.endpoint.read' || $id->value !== $ids[0]);
    expect($browser->options('endpoint'))->not->toHaveKey($ids[0]);
    $this->permissions(fn (): bool => false);
    expect(fn () => $browser->page('endpoint'))->toThrow(AuthorizationDenied::class);
    Livewire::test(Endpoints::class)->assertForbidden();
});
