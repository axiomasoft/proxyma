<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use Illuminate\Database\Connection;
use Proxyma\Infrastructure\Eloquent\BindingRevisionRecord;

/** @internal */
final class PanelBindingSchema
{
    public static function down(Connection $db): void
    {
        $t = PackageSchema::tables();
        $history = (new BindingRevisionRecord)->getTable();
        if ($db->table($history)->exists() || $db->table($t['audit_events'])->where('entity_type', 'bindings')->exists()
            || $db->table($t['bindings'])->where(fn ($q) => $q->where('panel_key', '<>', 'default')->orWhere('slot_key', '<>', 'default')->orWhere('policy_key', '<>', 'default'))->exists()) {
            throw new \LogicException('Binding history or scoped bindings prevent rollback.');
        }
        $db->statement("DROP TABLE {$history}");
        $db->statement("ALTER TABLE {$t['bindings']} DROP CONSTRAINT pxm_binding_scope_unique,
            DROP COLUMN panel_key, DROP COLUMN slot_key, DROP COLUMN policy_key,
            ADD CONSTRAINT pxm_binding_legacy_unique UNIQUE (tenant_key, subject_type, subject_id)");
        $db->statement("ALTER TABLE {$t['audit_events']} DROP CONSTRAINT pxm_audit_events_entity_type_check,
            ADD CONSTRAINT pxm_audit_events_entity_type_check CHECK (entity_type IN ('endpoints','routes','route_profiles','targets','shared_route_grants','policy_heads'))");
    }

    public static function up(Connection $db): void
    {
        $t = PackageSchema::tables();
        $table = $t['bindings'];
        $history = (new BindingRevisionRecord)->getTable();
        // Ищем старый unique по колонкам: PostgreSQL сокращает длинное имя constraint.
        $constraints = $db->select("SELECT conname FROM pg_constraint WHERE conrelid = ?::regclass AND contype = 'u'
            AND pg_get_constraintdef(oid) = 'UNIQUE (tenant_key, subject_type, subject_id)'", [$table]);
        foreach ($constraints as $constraint) {
            $name = str_replace('"', '""', $constraint->conname);
            $db->statement("ALTER TABLE {$table} DROP CONSTRAINT \"{$name}\"");
        }
        $db->statement("ALTER TABLE {$table}
            ADD COLUMN panel_key text COLLATE \"C\" NOT NULL DEFAULT 'default' CHECK (panel_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'),
            ADD COLUMN slot_key text COLLATE \"C\" NOT NULL DEFAULT 'default' CHECK (slot_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'),
            ADD COLUMN policy_key text COLLATE \"C\" NOT NULL DEFAULT 'default' CHECK (policy_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'),
            ADD CONSTRAINT pxm_binding_scope_unique UNIQUE (tenant_key, panel_key, subject_type, subject_id, slot_key)");
        $db->statement("CREATE TABLE {$history} (
            id uuid PRIMARY KEY DEFAULT gen_random_uuid(), tenant_key text NOT NULL, binding_id uuid NOT NULL,
            revision bigint NOT NULL CHECK (revision >= 1), policy_revision_id uuid NOT NULL, head_id uuid NOT NULL,
            profile_id uuid, policy_key text NOT NULL, retired boolean NOT NULL,
            created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
            UNIQUE (tenant_key, binding_id, revision),
            FOREIGN KEY (tenant_key, binding_id) REFERENCES {$table} (tenant_key, id),
            FOREIGN KEY (tenant_key, head_id, policy_revision_id) REFERENCES {$t['policy_revisions']} (tenant_key, head_id, id),
            FOREIGN KEY (tenant_key, profile_id) REFERENCES {$t['route_profiles']} (tenant_key, id))");
        $db->statement("INSERT INTO {$history} (tenant_key, binding_id, revision, policy_revision_id, head_id, profile_id, policy_key, retired)
            SELECT b.tenant_key, b.id, b.revision, h.current_revision_id, b.head_id, b.profile_id, b.policy_key, b.retired_at IS NOT NULL
            FROM {$table} b JOIN {$t['policy_heads']} h ON h.tenant_key = b.tenant_key AND h.id = b.head_id");
        $db->statement("CREATE TRIGGER pxm_binding_revisions_immutable BEFORE UPDATE OR DELETE ON {$history}
            FOR EACH ROW EXECUTE FUNCTION pxm_append_only_guard()");
        $db->statement("ALTER TABLE {$t['audit_events']} DROP CONSTRAINT pxm_audit_events_entity_type_check,
            ADD CONSTRAINT pxm_audit_events_entity_type_check CHECK (entity_type IN ('endpoints','routes','route_profiles','targets','shared_route_grants','policy_heads','bindings'))");
    }
}
