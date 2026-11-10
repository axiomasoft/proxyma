<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Proxyma\Infrastructure\Eloquent\AuditEventRecord;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('proxyma.connection', 'pgsql'));
        $audit = (new AuditEventRecord)->getTable();
        // Health migration потеряла ранее разрешённый bindings; исправляем и установленные БД.
        $db->statement("ALTER TABLE {$audit} DROP CONSTRAINT pxm_audit_events_entity_type_check,
            ADD CONSTRAINT pxm_audit_events_entity_type_check CHECK (entity_type IN
            ('endpoints','routes','route_profiles','targets','shared_route_grants','policy_heads','bindings','budgets','route_health','route_target_health','target_health'))");
    }

    public function down(): void
    {
        $db = DB::connection(config('proxyma.connection', 'pgsql'));
        $audit = (new AuditEventRecord)->getTable();
        if ($db->table($audit)->where('entity_type', 'bindings')->exists()) {
            throw new LogicException('Binding audit history prevents rollback.');
        }
        $db->statement("ALTER TABLE {$audit} DROP CONSTRAINT pxm_audit_events_entity_type_check,
            ADD CONSTRAINT pxm_audit_events_entity_type_check CHECK (entity_type IN
            ('endpoints','routes','route_profiles','targets','shared_route_grants','policy_heads','budgets','route_health','route_target_health','target_health'))");
    }
};
