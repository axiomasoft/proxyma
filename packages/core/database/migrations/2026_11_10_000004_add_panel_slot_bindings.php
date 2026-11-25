<?php

declare(strict_types=1);

use Proxyma\Database\ProxymaMigration;
use Proxyma\Infrastructure\Database\PanelBindingSchema;

return new class extends ProxymaMigration
{
    public function up(): void
    {
        PanelBindingSchema::up($this->db());
    }

    public function down(): void
    {
        PanelBindingSchema::down($this->db());
    }
};
