<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Proxyma\Filament\ProxymaFilamentServiceProvider;
use Proxyma\Laravel\ProxymaServiceProvider;

$providers = [];
if (getenv('PROXYMA_EXPLICIT_PROVIDERS') === '1') {
    $providers[] = ProxymaServiceProvider::class;
    if (getenv('PROXYMA_INSTALL_FILAMENT') === '1') {
        $providers[] = ProxymaFilamentServiceProvider::class;
    }
}

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders($providers)->withExceptions()->create();
