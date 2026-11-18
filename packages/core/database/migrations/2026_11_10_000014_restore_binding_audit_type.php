<?php

declare(strict_types=1);

use Proxyma\Database\ProxymaMigration;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Eloquent\AuditEventRecord;

return new class extends ProxymaMigration
{
    public function up(): void
    {
        $db = $this->db();
        $p = PackageSchema::prefix();
        $audit = (new AuditEventRecord)->getTable();
        // Health migration потеряла ранее разрешённый bindings; исправляем и установленные БД.
        $db->statement("ALTER TABLE {$audit} DROP CONSTRAINT {$p}audit_events_entity_type_check,
            ADD CONSTRAINT {$p}audit_events_entity_type_check CHECK (entity_type IN
            ('endpoints','routes','route_profiles','targets','shared_route_grants','policy_heads','bindings','budgets','route_health','route_target_health','target_health'))");
    }

    public function down(): void
    {
        $db = $this->db();
        $p = PackageSchema::prefix();
        $audit = (new AuditEventRecord)->getTable();
        if ($db->table($audit)->where('entity_type', 'bindings')->exists()) {
            throw new LogicException('Binding audit history prevents rollback.');
        }
        $db->statement("ALTER TABLE {$audit} DROP CONSTRAINT {$p}audit_events_entity_type_check,
            ADD CONSTRAINT {$p}audit_events_entity_type_check CHECK (entity_type IN
            ('endpoints','routes','route_profiles','targets','shared_route_grants','policy_heads','budgets','route_health','route_target_health','target_health'))");
    }
};
