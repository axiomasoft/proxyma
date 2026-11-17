<?php

declare(strict_types=1);

use Proxyma\Database\ProxymaMigration;
use Proxyma\Infrastructure\Security\TenantSecuritySchema;

return new class extends ProxymaMigration
{
    public function up(): void
    {
        TenantSecuritySchema::up($this->db());
    }

    public function down(): void
    {
        TenantSecuritySchema::down($this->db());
    }
};
