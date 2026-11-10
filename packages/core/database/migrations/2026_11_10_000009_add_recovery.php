<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Proxyma\Infrastructure\Database\RecoverySchema;

return new class extends Migration
{
    public function up(): void
    {
        RecoverySchema::up(DB::connection(config('proxyma.connection', 'pgsql')));
    }

    public function down(): void
    {
        RecoverySchema::down(DB::connection(config('proxyma.connection', 'pgsql')));
    }
};
