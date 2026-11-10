<?php

declare(strict_types=1);

namespace Consumer;

use Illuminate\Support\ServiceProvider;

class ConsumerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app['config']->set('proxyma.extensions', [
            'approved_providers' => ['consumer' => ConsumerExtensions::class], 'providers' => ['consumer'],
            'route_strategies' => ['consumer.first'], 'outcome_policies' => ['consumer.accept'],
        ]);
    }
}
