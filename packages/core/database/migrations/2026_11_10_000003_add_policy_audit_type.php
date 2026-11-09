<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Proxyma\Infrastructure\Eloquent\AuditEventRecord;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('proxyma.connection', 'pgsql');
    }

    public function up(): void
    {
        $table = (new AuditEventRecord)->getTable();
        DB::connection($this->getConnection())->statement("ALTER TABLE {$table} DROP CONSTRAINT pxm_audit_events_entity_type_check,
            ADD CONSTRAINT pxm_audit_events_entity_type_check CHECK (entity_type IN ('endpoints','routes','route_profiles','targets','shared_route_grants','policy_heads'))");
    }

    public function down(): void
    {
        $db = DB::connection($this->getConnection());
        $table = (new AuditEventRecord)->getTable();
        if ($db->table($table)->where('entity_type', 'policy_heads')->exists()) {
            throw new LogicException('Policy audit history prevents rollback.');
        }
        $db->statement("ALTER TABLE {$table} DROP CONSTRAINT pxm_audit_events_entity_type_check,
            ADD CONSTRAINT pxm_audit_events_entity_type_check CHECK (entity_type IN ('endpoints','routes','route_profiles','targets','shared_route_grants'))");
    }
};
