<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Proxyma\Infrastructure\Database\PackageSchema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('proxyma.connection', 'pgsql');
    }

    public function up(): void
    {
        PackageSchema::up(DB::connection($this->getConnection()));
    }

    public function down(): void
    {
        PackageSchema::down(DB::connection($this->getConnection()));
    }
};
