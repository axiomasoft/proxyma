<?php

declare(strict_types=1);

use Proxyma\Database\ProxymaMigration;
use Proxyma\Infrastructure\Database\ReservationSchema;

return new class extends ProxymaMigration
{
    public function up(): void
    {
        ReservationSchema::up($this->db());
    }

    public function down(): void
    {
        ReservationSchema::down($this->db());
    }
};
