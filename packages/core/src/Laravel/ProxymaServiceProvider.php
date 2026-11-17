<?php

declare(strict_types=1);

namespace Proxyma\Laravel;

use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;
use Proxyma\Laravel\Registrars\ProxymaAdmissionRegistrar;
use Proxyma\Laravel\Registrars\ProxymaCatalogRegistrar;
use Proxyma\Laravel\Registrars\ProxymaExecutionRegistrar;
use Proxyma\Laravel\Registrars\ProxymaPolicyRegistrar;

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
        $this->registerRegistrars();
    }

    public function boot(): void
    {
        $this->bootRegistrars();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/proxyma.php' => $this->app->configPath('proxyma.php'),
            ], 'proxyma-config');
        }
    }
}
