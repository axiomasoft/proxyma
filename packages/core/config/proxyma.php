<?php

declare(strict_types=1);

use Proxyma\Domain\Policy\TenantKey;

return [
    /*
    |--------------------------------------------------------------------------
    | Database Connection & Table Prefix
    |--------------------------------------------------------------------------
    |
    | PostgreSQL is the sole stateful authority backend in Proxyma 0.1.
    | All shared owner/financial/network claims must use the same connection.
    |
    */
    'connection' => env('PROXYMA_DB_CONNECTION', env('DB_CONNECTION', 'pgsql')),
    'table_prefix' => 'pxm_',

    /*
    |--------------------------------------------------------------------------
    | Default Tenant Scope
    |--------------------------------------------------------------------------
    |
    | Every query, command, and policy scope requires a non-empty tenant key.
    | Null wildcards are forbidden; untrusted request tenants are never authority.
    |
    */
    'default_tenant' => TenantKey::SYSTEM,

    // Provider IDs select only explicitly approved PHP class strings; no closures or objects.
    'settings' => [
        'approved_providers' => [],
        'providers' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Mandatory Durable Safety Stack (Decision D2)
    |--------------------------------------------------------------------------
    |
    | Admission, budget enforcement, and network health are always active in 0.1.
    | There is no stateless or NullAuthority bypass mode.
    |
    */
    'safety' => [
        'admission' => 'mandatory',
        'budgets' => 'mandatory',
        'health' => 'mandatory',
    ],

    /*
    |--------------------------------------------------------------------------
    | Transaction Timeouts
    |--------------------------------------------------------------------------
    */
    'transactions' => [
        'lock_timeout' => '2s',
        'statement_timeout' => '5s',
        'max_attempts' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Registered Extension Contracts
    |--------------------------------------------------------------------------
    */
    'extensions' => [
        'policy_definitions' => [],
        'route_strategies' => [],
        'outcome_policies' => [],
        'route_filters' => [],
        'budget_contributors' => [],
        'locked_admission_guards' => [],
        'observers' => [],
    ],
];
