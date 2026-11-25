<?php

declare(strict_types=1);

use Proxyma\Database\ProxymaMigration;
use Proxyma\Infrastructure\Database\RecoverySchema;

return new class extends ProxymaMigration
{
    public function up(): void
    {
        RecoverySchema::up($this->db());
    }

    public function down(): void
    {
        RecoverySchema::down($this->db());
    }
};
