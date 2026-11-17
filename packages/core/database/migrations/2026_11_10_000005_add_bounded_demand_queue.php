<?php

declare(strict_types=1);

use Proxyma\Database\ProxymaMigration;
use Proxyma\Infrastructure\Database\DemandQueueSchema;

return new class extends ProxymaMigration
{
    public function up(): void
    {
        DemandQueueSchema::up($this->db());
    }

    public function down(): void
    {
        DemandQueueSchema::down($this->db());
    }
};
