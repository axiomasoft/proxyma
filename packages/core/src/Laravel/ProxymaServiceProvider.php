<?php

declare(strict_types=1);

namespace Proxyma\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Laravel\ExtensionRegistrar;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use Proxyma\Laravel\Registrars\ProxymaAdmissionRegistrar;
use Proxyma\Laravel\Registrars\ProxymaCatalogRegistrar;
use Proxyma\Laravel\Registrars\ProxymaExecutionRegistrar;
use Proxyma\Laravel\Registrars\ProxymaPolicyRegistrar;
use Proxyma\Plugins\ProxymaCorePlugin;

/**
 * Registers Proxyma's core runtime, policy, admission, and execution registrars.
 */
#[Api(since: '1.0')]
final class ProxymaServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars {
        register as protected registerRegistrars;
        boot as protected bootRegistrars;
    }

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        ProxymaCatalogRegistrar::class,
        ProxymaPolicyRegistrar::class,
        ProxymaAdmissionRegistrar::class,
        ProxymaExecutionRegistrar::class,
    ];

    #[Override]
    public function register(): void
    {
        if (! $this->app->bound(PluginInspector::class)) {
            $this->app->register(PluginsServiceProvider::class);
        }
        ExtensionRegistrar::builtInFor($this->app, ProxymaCorePlugin::class);
        $this->app->bindIf(ProxymaCorePlugin::class, static fn (Application $app): ProxymaCorePlugin => new ProxymaCorePlugin(TablePrefixResolver::forApplication($app)));
    }

    public function boot(): void
    {
        if ($this->app->make(PluginInspector::class)->facetsOf('axiomasoft.proxyma') === null
            || $this->app->bound(self::class.'.activated')) {
            return;
        }

        $this->app->instance(self::class.'.activated', true);
        $this->registerRegistrars();
        $this->bootRegistrars();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/proxyma.php' => $this->app->configPath('proxyma.php'),
            ], 'proxyma-config');
        }
    }
}
