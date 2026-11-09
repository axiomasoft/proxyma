<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Proxyma\Infrastructure\Database\PanelBindingSchema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('proxyma.connection', 'pgsql');
    }

    public function up(): void
    {
        PanelBindingSchema::up(DB::connection($this->getConnection()));
    }

    public function down(): void
    {
        PanelBindingSchema::down(DB::connection($this->getConnection()));
    }
};
