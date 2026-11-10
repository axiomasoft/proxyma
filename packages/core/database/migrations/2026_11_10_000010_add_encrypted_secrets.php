<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Secrets\SecretSchema;

return new class extends Migration
{
    public function up(): void
    {
        SecretSchema::up(DB::connection(config('proxyma.connection', 'pgsql')));
    }

    public function down(): void
    {
        $table = PackageSchema::tables()['encrypted_secrets'];
        DB::connection(config('proxyma.connection', 'pgsql'))->statement("DROP TABLE {$table}");
    }
};
