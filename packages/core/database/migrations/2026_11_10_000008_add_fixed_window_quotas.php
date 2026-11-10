<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Proxyma\Infrastructure\Database\QuotaSchema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('proxyma.connection', 'pgsql');
    }

    public function up(): void
    {
        QuotaSchema::up(DB::connection($this->getConnection()));
    }

    public function down(): void
    {
        QuotaSchema::down(DB::connection($this->getConnection()));
    }
};
