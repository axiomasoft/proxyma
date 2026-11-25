<?php

use Consumer\ConsumerServiceProvider;
use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([ConsumerServiceProvider::class])->create();
