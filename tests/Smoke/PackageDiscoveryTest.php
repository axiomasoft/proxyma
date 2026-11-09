<?php

use Proxyma\Filament\ProxymaFilamentServiceProvider;
use Proxyma\Laravel\ProxymaServiceProvider;

it('loads both package providers', function () {
    $core = json_decode(file_get_contents(dirname(__DIR__, 2).'/packages/core/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $filament = json_decode(file_get_contents(dirname(__DIR__, 2).'/packages/filament/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($core['extra']['laravel']['providers'])->toContain(ProxymaServiceProvider::class);
    expect($filament['extra']['laravel']['providers'])->toContain(ProxymaFilamentServiceProvider::class);
    expect(class_exists(ProxymaServiceProvider::class))->toBeTrue();
    expect(class_exists(ProxymaFilamentServiceProvider::class))->toBeTrue();
    expect(app()->getProvider(ProxymaServiceProvider::class))->not->toBeNull();
    expect(app()->getProvider(ProxymaFilamentServiceProvider::class))->not->toBeNull();
});
