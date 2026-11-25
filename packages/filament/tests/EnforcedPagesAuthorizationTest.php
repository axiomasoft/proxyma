<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Packman\Filament\Concerns\Authorization\AuthorizesPage;
use Proxyma\Filament\Pages\Budgets\BudgetsPage;
use Proxyma\Filament\Pages\Health\HealthPage;
use Proxyma\Filament\Pages\Operations\AttemptsPage;
use Proxyma\Filament\Pages\Operations\AuditPage;
use Proxyma\Filament\Pages\Policy\BindingsPage;
use Proxyma\Filament\Pages\Policy\PolicyInspector;
use Proxyma\Filament\ProxymaPlugin;
use Proxyma\Filament\Resources\Pages\Endpoints;
use Proxyma\Filament\Resources\Pages\RouteProfiles;
use Proxyma\Filament\Resources\Pages\Routes;
use Proxyma\Filament\Tests\Support\WithBindingEditor;
use Proxyma\Filament\Tests\Support\WithFilament;
use Proxyma\Tests\Support\CatalogTestCase;

require_once __DIR__.'/../../core/tests/Support/CatalogTestCase.php';
require_once __DIR__.'/Support/WithFilament.php';
require_once __DIR__.'/Support/WithBindingEditor.php';

uses(CatalogTestCase::class, WithFilament::class, WithBindingEditor::class)->group('pg');

const PROXYMA_ALL_PAGES = [
    Endpoints::class,
    Routes::class,
    RouteProfiles::class,
    BindingsPage::class,
    PolicyInspector::class,
    BudgetsPage::class,
    HealthPage::class,
    AttemptsPage::class,
    AuditPage::class,
];

beforeEach(function (): void {
    $this->prepareBindings();
});

it('all 9 Proxyma pages compose Packman AuthorizesPage without overriding canAccess', function (): void {
    $panelPages = Filament::getCurrentPanel()?->getPages() ?? [];
    expect($panelPages)->toHaveCount(9);

    $traitFile = new ReflectionClass(AuthorizesPage::class)->getFileName();

    foreach (PROXYMA_ALL_PAGES as $pageClass) {
        expect($panelPages)->toContain($pageClass);
        $method = new ReflectionMethod($pageClass, 'canAccess');
        expect($method->getFileName())->toBe($traitFile);
    }
});

it('allows mount and Livewire hydration across all 9 pages when enforce() is enabled and authorized', function (): void {
    ProxymaPlugin::get()->enforce();

    foreach (PROXYMA_ALL_PAGES as $pageClass) {
        expect($pageClass::canAccess())->toBeTrue();

        $component = Livewire::test($pageClass)->assertOk();

        if (in_array($pageClass, [BindingsPage::class, PolicyInspector::class], true)) {
            $component->set('selection.panel', 'files')->assertOk();
        } else {
            $component->call('changePage', 1)->assertOk();
        }
    }
});

it('denies guest mount and blocks all 9 pages on ->enforce()', function (): void {
    ProxymaPlugin::get()->enforce();
    auth()->logout();

    foreach (PROXYMA_ALL_PAGES as $pageClass) {
        expect($pageClass::canAccess())->toBeFalse();
        Livewire::test($pageClass)->assertForbidden();
    }
});

it('denies Authorizer-revoked actor on mount and during Livewire hydration under ->enforce()', function (): void {
    ProxymaPlugin::get()->enforce();

    $mounted = [
        Livewire::test(Endpoints::class)->assertOk(),
        Livewire::test(BudgetsPage::class)->assertOk(),
        Livewire::test(BindingsPage::class)->assertOk(),
    ];

    $this->permissions(fn (): bool => false);

    foreach (PROXYMA_ALL_PAGES as $pageClass) {
        expect($pageClass::canAccess())->toBeFalse();
        Livewire::test($pageClass)->assertForbidden();
    }

    $mounted[0]->call('changePage', 2)->assertForbidden();
    $mounted[1]->call('changePage', 2)->assertForbidden();
    $mounted[2]->set('selection.panel', 'files')->assertForbidden();
});

it('denies Livewire hydration under ->enforce() when user logs out mid-session or host Gate / guardPanel denies', function (): void {
    ProxymaPlugin::get()->enforce();
    $component = Livewire::test(Endpoints::class)->assertOk();

    auth()->logout();
    $component->call('changePage', 2)->assertForbidden();

    $this->prepareFilament();
    Gate::define('pages.proxyma-endpoints', static fn (): bool => false);
    expect(Endpoints::canAccess())->toBeFalse();
    Livewire::test(Endpoints::class)->assertForbidden();

    Gate::define('pages.proxyma-endpoints', static fn (): bool => true);
    ProxymaPlugin::get()->guardPanel('missing-guard-panel')->enforce();
    expect(Endpoints::canAccess())->toBeFalse();
    Livewire::test(Endpoints::class)->assertForbidden();
});

it('isolates ProxymaPlugin instances across multiple Filament panels', function (): void {
    $adminPanel = Panel::make()
        ->id('admin')
        ->path('admin')
        ->plugin(ProxymaPlugin::make()->guardPanel('admin')->enforce());

    $operatorPanel = Panel::make()
        ->id('operator')
        ->path('operator')
        ->plugin(ProxymaPlugin::make()->guardPanel('operator')->enforce(false));

    $adminPlugin = ProxymaPlugin::current($adminPanel);
    $operatorPlugin = ProxymaPlugin::current($operatorPanel);

    expect($adminPlugin)->not->toBe($operatorPlugin)
        ->and($adminPlugin->getGuardPanel())->toBe('admin')
        ->and($adminPlugin->isEnforced())->toBeTrue()
        ->and($operatorPlugin->getGuardPanel())->toBe('operator')
        ->and($operatorPlugin->isEnforced())->toBeFalse();
});
