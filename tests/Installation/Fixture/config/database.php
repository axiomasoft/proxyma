<?php

declare(strict_types=1);

return [
    'default' => 'pgsql',
    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql', 'host' => 'db', 'port' => env('PROXYMA_INSTALL_DB_PORT', 5432),
            'database' => 'proxyma_test', 'username' => 'proxyma_test', 'password' => 'proxyma-test-only',
            'charset' => 'utf8', 'prefix' => '', 'search_path' => env('PROXYMA_INSTALL_SCHEMA'), 'sslmode' => 'prefer',
        ],
    ],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
];
