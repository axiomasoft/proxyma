<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Secrets;

use Illuminate\Database\Connection;
use Proxyma\Infrastructure\Database\PackageSchema;

final class SecretSchema
{
    public static function up(Connection $db): void
    {
        $t = PackageSchema::tables();
        $db->statement("CREATE TABLE {$t['encrypted_secrets']} (
            tenant_key text COLLATE \"C\" NOT NULL,
            reference text NOT NULL CHECK (reference ~ '^[0-9a-f-]{36}$'),
            endpoint_id uuid NOT NULL,
            version bigint NOT NULL CHECK (version >= 1),
            ciphertext text NOT NULL CHECK (octet_length(ciphertext) > 0),
            created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
            PRIMARY KEY (tenant_key, reference), UNIQUE (tenant_key, endpoint_id),
            FOREIGN KEY (tenant_key, endpoint_id) REFERENCES {$t['endpoints']} (tenant_key, id) ON DELETE RESTRICT
        )");
    }
}
