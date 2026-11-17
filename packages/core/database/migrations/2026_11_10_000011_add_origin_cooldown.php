<?php

declare(strict_types=1);

use Proxyma\Database\ProxymaMigration;
use Proxyma\Infrastructure\Eloquent\BudgetRecord;

return new class extends ProxymaMigration
{
    public function up(): void
    {
        $table = (new BudgetRecord)->getTable();
        $this->db()->statement(
            "ALTER TABLE {$table} ADD COLUMN cooldown_until timestamptz NOT NULL DEFAULT '-infinity',
                ADD CONSTRAINT pxm_cooldown_finite CHECK (cooldown_until <> 'infinity'::timestamptz)",
        );
    }

    public function down(): void
    {
        $table = (new BudgetRecord)->getTable();
        $this->db()->statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS pxm_cooldown_finite, DROP COLUMN IF EXISTS cooldown_until");
    }
};
