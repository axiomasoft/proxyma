<?php

declare(strict_types=1);

return [
    'default' => 'pgsql',
    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql', 'host' => env('PROXYMA_INSTALL_DB_HOST', 'db'), 'port' => env('PROXYMA_INSTALL_DB_PORT', 5432),
            'database' => 'proxyma_test', 'username' => env('PROXYMA_INSTALL_DB_USERNAME', 'proxyma_test'), 'password' => env('PROXYMA_INSTALL_DB_PASSWORD', 'proxyma-test-only'),
            'charset' => 'utf8', 'prefix' => '', 'search_path' => env('PROXYMA_INSTALL_SCHEMA'), 'sslmode' => 'prefer',
        ],
    ],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
];
