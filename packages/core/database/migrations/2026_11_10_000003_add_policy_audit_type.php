<?php

declare(strict_types=1);

use Proxyma\Database\ProxymaMigration;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Eloquent\AuditEventRecord;

return new class extends ProxymaMigration
{
    public function up(): void
    {
        $p = PackageSchema::prefix();
        $table = (new AuditEventRecord)->getTable();
        $this->db()->statement("ALTER TABLE {$table} DROP CONSTRAINT {$p}audit_events_entity_type_check,
            ADD CONSTRAINT {$p}audit_events_entity_type_check CHECK (entity_type IN ('endpoints','routes','route_profiles','targets','shared_route_grants','policy_heads'))");
    }

    public function down(): void
    {
        $db = $this->db();
        $p = PackageSchema::prefix();
        $table = (new AuditEventRecord)->getTable();
        if ($db->table($table)->where('entity_type', 'policy_heads')->exists()) {
            throw new LogicException('Policy audit history prevents rollback.');
        }
        $db->statement("ALTER TABLE {$table} DROP CONSTRAINT {$p}audit_events_entity_type_check,
            ADD CONSTRAINT {$p}audit_events_entity_type_check CHECK (entity_type IN ('endpoints','routes','route_profiles','targets','shared_route_grants'))");
    }
};
