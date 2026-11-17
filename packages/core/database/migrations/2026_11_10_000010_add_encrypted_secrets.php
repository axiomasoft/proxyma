<?php

declare(strict_types=1);

use Proxyma\Database\ProxymaMigration;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Secrets\SecretSchema;

return new class extends ProxymaMigration
{
    public function up(): void
    {
        SecretSchema::up($this->db());
    }

    public function down(): void
    {
        $table = PackageSchema::tables()['encrypted_secrets'];
        $this->db()->statement("DROP TABLE {$table}");
    }
};
