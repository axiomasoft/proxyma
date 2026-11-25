<?php

declare(strict_types=1);

use Proxyma\Database\ProxymaMigration;
use Proxyma\Infrastructure\Database\PackageSchema;

return new class extends ProxymaMigration
{
    public function up(): void
    {
        $db = $this->db();
        $p = PackageSchema::prefix($this->db());
        $claims = PackageSchema::table('probe_claims', $this->db());
        $operations = PackageSchema::table('operations', $this->db());
        $health = PackageSchema::table('route_health', $this->db());
        $endpoints = PackageSchema::table('endpoints', $this->db());
        $routes = PackageSchema::table('routes', $this->db());
        $audit = PackageSchema::table('audit_events', $this->db());
        $db->statement("ALTER TABLE {$audit} DROP CONSTRAINT {$p}audit_events_entity_type_check,
            ADD CONSTRAINT {$p}audit_events_entity_type_check CHECK (entity_type IN
            ('endpoints','routes','route_profiles','targets','shared_route_grants','policy_heads','budgets','route_health','route_target_health','target_health'))");
        $db->statement("CREATE TABLE {$claims} (
            id uuid PRIMARY KEY, tenant_key text COLLATE \"C\" NOT NULL,
            operation_id uuid NOT NULL, route_id uuid NOT NULL, target_id uuid NOT NULL,
            epochs jsonb NOT NULL CHECK (jsonb_typeof(epochs) = 'object' AND epochs <> '{}'::jsonb),
            purpose text NOT NULL DEFAULT 'probe' CHECK (purpose = 'probe'),
            created_at timestamptz NOT NULL, expires_at timestamptz NOT NULL,
            CHECK (isfinite(created_at) AND isfinite(expires_at) AND expires_at > created_at AND expires_at <= created_at + interval '300 seconds'),
            physical_attempt_id uuid,
            FOREIGN KEY (tenant_key, operation_id) REFERENCES {$operations} (tenant_key, id) ON DELETE RESTRICT)");
        $db->statement("ALTER TABLE {$health} ADD COLUMN credential_endpoint_id uuid, ADD COLUMN credential_version bigint,
            ADD CONSTRAINT {$p}credential_hold_identity CHECK ((credential_endpoint_id IS NULL) = (credential_version IS NULL) AND credential_version >= 1),
            ADD FOREIGN KEY (tenant_key, credential_endpoint_id) REFERENCES {$endpoints} (tenant_key, id) ON DELETE RESTRICT");
        $db->statement("UPDATE {$health} h SET credential_endpoint_id = r.endpoint_id,
            credential_version = substring(h.reason_code from '^credential_hold:([0-9]+)$')::bigint
            FROM {$routes} r WHERE h.route_id = r.id AND h.tenant_key = r.tenant_key AND r.endpoint_id IS NOT NULL
            AND h.reason_code ~ '^credential_hold:[0-9]+$'");
    }

    public function down(): void
    {
        $db = $this->db();
        $p = PackageSchema::prefix($this->db());
        $audit = PackageSchema::table('audit_events', $this->db());
        if ($db->table($audit)->whereIn('entity_type', ['route_health', 'route_target_health', 'target_health'])->exists()) {
            throw new LogicException('Health audit history prevents rollback.');
        }
        $db->statement("ALTER TABLE {$audit} DROP CONSTRAINT {$p}audit_events_entity_type_check,
            ADD CONSTRAINT {$p}audit_events_entity_type_check CHECK (entity_type IN
            ('endpoints','routes','route_profiles','targets','shared_route_grants','policy_heads','budgets'))");
        $db->statement('DROP TABLE '.PackageSchema::table('probe_claims', $this->db()));
        $db->statement('ALTER TABLE '.PackageSchema::table('route_health', $this->db()).' DROP COLUMN credential_endpoint_id, DROP COLUMN credential_version');
    }
};
