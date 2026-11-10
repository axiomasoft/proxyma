<?php

declare(strict_types=1);

namespace Proxyma\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Proxyma\Filament\Pages\AttemptsPage;
use Proxyma\Filament\Pages\AuditPage;
use Proxyma\Filament\Pages\BindingsPage;
use Proxyma\Filament\Pages\BudgetsPage;
use Proxyma\Filament\Pages\HealthPage;
use Proxyma\Filament\Pages\PolicyInspector;
use Proxyma\Filament\Resources\Pages\Endpoints;
use Proxyma\Filament\Resources\Pages\RouteProfiles;
use Proxyma\Filament\Resources\Pages\Routes;

class ProxymaPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'proxyma';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([Endpoints::class, Routes::class, RouteProfiles::class, BindingsPage::class, PolicyInspector::class,
            BudgetsPage::class, HealthPage::class, AttemptsPage::class, AuditPage::class]);
    }

    public function boot(Panel $panel): void {}
}
