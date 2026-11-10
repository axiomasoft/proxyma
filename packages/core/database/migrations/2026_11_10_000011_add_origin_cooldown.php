<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Proxyma\Infrastructure\Eloquent\BudgetRecord;

return new class extends Migration
{
    public function up(): void
    {
        $table = (new BudgetRecord)->getTable();
        DB::connection(config('proxyma.connection', 'pgsql'))->statement(
            "ALTER TABLE {$table} ADD COLUMN cooldown_until timestamptz NOT NULL DEFAULT '-infinity',
                ADD CONSTRAINT pxm_cooldown_finite CHECK (cooldown_until <> 'infinity'::timestamptz)",
        );
    }
};
