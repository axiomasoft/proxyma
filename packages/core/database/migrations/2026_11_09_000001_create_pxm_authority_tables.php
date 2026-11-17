<?php

declare(strict_types=1);

use Proxyma\Database\ProxymaMigration;
use Proxyma\Infrastructure\Database\PackageSchema;

return new class extends ProxymaMigration
{
    public function up(): void
    {
        PackageSchema::up($this->db());
    }

    public function down(): void
    {
        PackageSchema::down($this->db());
    }
};
