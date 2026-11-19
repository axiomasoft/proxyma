<?php

declare(strict_types=1);

use Proxyma\Database\ProxymaMigration;
use Proxyma\Infrastructure\Database\PackageSchema;

return new class extends ProxymaMigration
{
    public function up(): void
    {
        $p = PackageSchema::prefix($this->db());
        $table = PackageSchema::table('budgets', $this->db());
        $this->db()->statement(
            "ALTER TABLE {$table} ADD COLUMN cooldown_until timestamptz NOT NULL DEFAULT '-infinity',
                ADD CONSTRAINT {$p}cooldown_finite CHECK (cooldown_until <> 'infinity'::timestamptz)",
        );
    }

    public function down(): void
    {
        $p = PackageSchema::prefix($this->db());
        $table = PackageSchema::table('budgets', $this->db());
        $this->db()->statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$p}cooldown_finite, DROP COLUMN IF EXISTS cooldown_until");
    }
};
