<?php

declare(strict_types=1);

namespace Proxyma\Filament;

use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;
use Proxyma\Filament\Registrars\ProxymaFilamentRegistrar;

/**
 * Registers Proxyma Filament integration services and Blade view namespace.
 */
#[Api(since: '1.0')]
final class ProxymaFilamentServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars {
        register as protected registerRegistrars;
        boot as protected bootRegistrars;
    }

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        ProxymaFilamentRegistrar::class,
    ];

    #[Override]
    public function register(): void
    {
        $this->registerRegistrars();
    }

    public function boot(): void
    {
        $this->bootRegistrars();
    }
}
