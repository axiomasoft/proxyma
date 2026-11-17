<?php

declare(strict_types=1);

namespace Proxyma\Core\Tests\Unit;

use Packman\PackmanServiceProvider;
use Packman\Panels\Laravel\PanelsServiceProvider;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use Packman\Schema\Laravel\SchemaServiceProvider;
use Packman\Testkit\Concerns\Conformance\PluginContractTests;
use Packman\Testkit\PackageTestCase;
use Proxyma\Laravel\ProxymaServiceProvider;
use Proxyma\Plugins\ProxymaCorePlugin;

final class ProxymaCorePluginConformanceTest extends PackageTestCase
{
    use PluginContractTests;

    protected function pluginUnderTest(): ExtensionPlugin
    {
        return new ProxymaCorePlugin;
    }

    protected function getPackageProviders($app): array
    {
        return [
            PackmanServiceProvider::class,
            SchemaServiceProvider::class,
            PluginsServiceProvider::class,
            PanelsServiceProvider::class,
            ProxymaServiceProvider::class,
        ];
    }
}
