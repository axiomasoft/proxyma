<?php

declare(strict_types=1);

namespace Proxyma\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Proxyma\Contracts\TransactionCoordinator;

class ProxymaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/proxyma.php', 'proxyma');

        $this->app->bind(TransactionCoordinator::class, static function (Application $app): TransactionCoordinator {
            $connection = $app['config']->get('proxyma.connection', 'pgsql');

            return new DatabaseTransactionCoordinator(is_string($connection) && $connection !== '' ? $connection : 'pgsql');
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/proxyma.php' => $this->app->configPath('proxyma.php'),
            ], 'proxyma-config');
        }
    }
}
