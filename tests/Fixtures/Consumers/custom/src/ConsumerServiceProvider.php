<?php

declare(strict_types=1);

namespace Consumer;

use Illuminate\Support\ServiceProvider;

class ConsumerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $existing = $this->app['config']->get('packman.plugins', []);
        $plugins = is_array($existing) ? $existing : [];
        $this->app['config']->set('packman.plugins', array_values(array_unique([...$plugins, ConsumerExtensions::class])));

        $this->app['config']->set('proxyma.extensions', [
            'route_strategies' => ['consumer.first'],
            'outcome_policies' => ['consumer.accept'],
        ]);
    }
}
