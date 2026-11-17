<?php

declare(strict_types=1);

namespace Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Proxyma\Filament\ProxymaFilamentServiceProvider;
use Proxyma\Laravel\ProxymaServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [ProxymaServiceProvider::class, ProxymaFilamentServiceProvider::class];
    }
}
