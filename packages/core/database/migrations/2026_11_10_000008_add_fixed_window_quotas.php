<?php

declare(strict_types=1);

use Proxyma\Database\ProxymaMigration;
use Proxyma\Infrastructure\Database\QuotaSchema;

return new class extends ProxymaMigration
{
    public function up(): void
    {
        QuotaSchema::up($this->db());
    }

    public function down(): void
    {
        QuotaSchema::down($this->db());
    }
};
