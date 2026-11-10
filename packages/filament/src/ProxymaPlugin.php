<?php

declare(strict_types=1);

namespace Proxyma\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
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
        $panel->pages([Endpoints::class, Routes::class, RouteProfiles::class]);
    }

    public function boot(Panel $panel): void {}
}
