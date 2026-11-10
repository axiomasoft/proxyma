<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Proxyma\Infrastructure\Database\DemandQueueSchema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('proxyma.connection', 'pgsql');
    }

    public function up(): void
    {
        DemandQueueSchema::up(DB::connection($this->getConnection()));
    }

    public function down(): void
    {
        DemandQueueSchema::down(DB::connection($this->getConnection()));
    }
};
