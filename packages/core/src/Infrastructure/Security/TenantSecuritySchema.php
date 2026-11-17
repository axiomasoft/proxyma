<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Security;

use Illuminate\Database\Connection;
use Packman\Attributes\Internal;
use Proxyma\Infrastructure\Audit\CatalogAudit;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Internal\Database\RowValues;
use RuntimeException;

/** @internal Расширение установленной P1.3 схемы без переустановки каталога. */
#[Internal]
final class TenantSecuritySchema
{
    public static function up(Connection $db): void
    {
        $t = PackageSchema::tables();
        $db->statement("CREATE TABLE {$t['shared_route_grants']} (
            id uuid PRIMARY KEY DEFAULT gen_random_uuid(), tenant_key text COLLATE \"C\" NOT NULL,
            consumer_tenant_key text COLLATE \"C\" NOT NULL CHECK (consumer_tenant_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'),
            route_id uuid NOT NULL, revision bigint NOT NULL DEFAULT 1 CHECK (revision >= 1), retired_at timestamptz,
            created_at timestamptz NOT NULL DEFAULT clock_timestamp(), updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
            CHECK (tenant_key <> consumer_tenant_key), UNIQUE (tenant_key, id),
            UNIQUE (tenant_key, consumer_tenant_key, route_id, id),
            FOREIGN KEY (tenant_key, route_id) REFERENCES {$t['routes']} (tenant_key, id) ON DELETE RESTRICT)");
        $db->statement("CREATE TABLE {$t['catalog_command_receipts']} (
            id uuid PRIMARY KEY DEFAULT gen_random_uuid(), tenant_key text COLLATE \"C\" NOT NULL,
            audit_event_id uuid NOT NULL, idempotency_key text COLLATE \"C\" NOT NULL,
            command_hash text NOT NULL CHECK (command_hash ~ '^[0-9a-f]{64}$'),
            result jsonb NOT NULL CHECK (jsonb_typeof(result) = 'object'),
            UNIQUE (tenant_key, id), UNIQUE (tenant_key, idempotency_key),
            FOREIGN KEY (tenant_key, audit_event_id) REFERENCES {$t['audit_events']} (tenant_key, id))");

        // Старые audit snapshots остаются только в закрытом command receipt; журнал содержит имена полей.
        $db->statement("DROP TRIGGER pxm_audit_events_immutable ON {$t['audit_events']}");
        foreach ($db->table($t['audit_events'])->orderBy('id')->cursor() as $event) {
            $eventId = RowValues::colStr($event, 'id');
            $key = hash('sha256', RowValues::colStr($event, 'idempotency_key'));
            $decodedResult = json_decode(RowValues::colStr($event, 'result'), false, flags: JSON_THROW_ON_ERROR);
            $db->table($t['catalog_command_receipts'])->insert([
                'tenant_key' => RowValues::colStr($event, 'tenant_key'),
                'audit_event_id' => $eventId,
                'idempotency_key' => $key,
                'command_hash' => RowValues::colStr($event, 'command_hash'),
                'result' => json_encode(CatalogAudit::snapshot(RowValues::colStr($event, 'entity_type'), RowValues::object($decodedResult)), JSON_THROW_ON_ERROR),
            ]);
            $db->table($t['audit_events'])->where('id', $eventId)->update([
                'idempotency_key' => $key,
                'actor_id' => hash('sha256', RowValues::colStr($event, 'actor_id')),
                'reason' => hash('sha256', RowValues::colStr($event, 'reason')),
                'result' => json_encode(['before_fields' => [], 'after_fields' => [], 'legacy_redacted' => true], JSON_THROW_ON_ERROR),
            ]);
        }
        $revisionChecks = $db->select("SELECT conname FROM pg_constraint WHERE conrelid = ?::regclass AND contype = 'c'
            AND pg_get_constraintdef(oid) LIKE '%previous_revision + 1%'", [$t['audit_events']]);
        if (count($revisionChecks) !== 1) {
            throw new RuntimeException('Expected the P1.3 audit revision constraint.');
        }
        $revisionCheck = RowValues::colStr(RowValues::object($revisionChecks[0]), 'conname');
        $db->statement("ALTER TABLE {$t['audit_events']} DROP CONSTRAINT pxm_audit_events_entity_type_check,
            DROP CONSTRAINT \"{$revisionCheck}\",
            ADD COLUMN event_kind text NOT NULL DEFAULT 'mutation' CHECK (event_kind IN ('mutation','read')),
            ADD CHECK (entity_type IN ('endpoints','routes','route_profiles','targets','shared_route_grants')),
            ADD CHECK ((event_kind = 'mutation' AND revision = previous_revision + 1)
                OR (event_kind = 'read' AND revision = previous_revision AND revision >= 0))");
        $db->statement("CREATE TRIGGER pxm_audit_events_immutable BEFORE UPDATE OR DELETE ON {$t['audit_events']} FOR EACH ROW EXECUTE FUNCTION pxm_append_only_guard()");
        $db->statement("CREATE TRIGGER pxm_receipts_immutable BEFORE UPDATE OR DELETE ON {$t['catalog_command_receipts']} FOR EACH ROW EXECUTE FUNCTION pxm_append_only_guard()");

        $foreign = $db->select("SELECT conname FROM pg_constraint WHERE conrelid = ?::regclass AND confrelid = ?::regclass AND contype = 'f'", [$t['route_profile_members'], $t['routes']]);
        foreach ($foreign as $constraint) {
            $conname = RowValues::colStr(RowValues::object($constraint), 'conname');
            $db->statement("ALTER TABLE {$t['route_profile_members']} DROP CONSTRAINT \"{$conname}\"");
        }
        $db->statement("ALTER TABLE {$t['route_profile_members']} ADD COLUMN route_owner_tenant_key text COLLATE \"C\",
            ADD COLUMN shared_grant_id uuid");
        $db->statement("UPDATE {$t['route_profile_members']} SET route_owner_tenant_key = tenant_key");
        $db->statement("ALTER TABLE {$t['route_profile_members']} ALTER COLUMN route_owner_tenant_key SET NOT NULL,
            ADD CHECK ((route_owner_tenant_key = tenant_key AND shared_grant_id IS NULL)
                OR (route_owner_tenant_key <> tenant_key AND shared_grant_id IS NOT NULL)),
            ADD FOREIGN KEY (route_owner_tenant_key, route_id) REFERENCES {$t['routes']} (tenant_key, id),
            ADD FOREIGN KEY (route_owner_tenant_key, tenant_key, route_id, shared_grant_id)
                REFERENCES {$t['shared_route_grants']} (tenant_key, consumer_tenant_key, route_id, id)");
        $db->statement(<<<SQL
            CREATE FUNCTION pxm_member_grant_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                NEW.route_owner_tenant_key := COALESCE(NEW.route_owner_tenant_key, NEW.tenant_key);
                IF NEW.shared_grant_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM {$t['shared_route_grants']} g WHERE g.id = NEW.shared_grant_id
                        AND g.tenant_key = NEW.route_owner_tenant_key AND g.consumer_tenant_key = NEW.tenant_key
                        AND g.route_id = NEW.route_id AND g.retired_at IS NULL FOR SHARE
                ) THEN
                    RAISE EXCEPTION 'inactive or mismatched shared grant' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$
            SQL);
        $db->statement(<<<SQL
            CREATE FUNCTION pxm_grant_revision_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'grant history requires revocation' USING ERRCODE = '23514';
                END IF;
                IF ROW(NEW.id, NEW.tenant_key, NEW.consumer_tenant_key, NEW.route_id)
                    IS DISTINCT FROM ROW(OLD.id, OLD.tenant_key, OLD.consumer_tenant_key, OLD.route_id)
                    OR NEW.revision <> OLD.revision + 1 OR OLD.retired_at IS NOT NULL THEN
                    RAISE EXCEPTION 'grant identity or revision violation' USING ERRCODE = '23514';
                END IF;
                UPDATE {$t['bindings']} b SET revision = b.revision + 1, updated_at = clock_timestamp()
                    WHERE b.tenant_key = NEW.consumer_tenant_key AND b.profile_id IN (
                        SELECT m.profile_id FROM {$t['route_profile_members']} m
                        WHERE m.tenant_key = NEW.consumer_tenant_key AND m.shared_grant_id = NEW.id);
                RETURN NEW;
            END $$
            SQL);
        $db->statement("CREATE TRIGGER pxm_member_grant BEFORE INSERT OR UPDATE ON {$t['route_profile_members']} FOR EACH ROW EXECUTE FUNCTION pxm_member_grant_guard()");
        $db->statement("CREATE TRIGGER pxm_grant_revision BEFORE UPDATE OR DELETE ON {$t['shared_route_grants']} FOR EACH ROW EXECUTE FUNCTION pxm_grant_revision_guard()");
        $db->statement("CREATE INDEX pxm_grant_consumer_idx ON {$t['shared_route_grants']} (consumer_tenant_key, route_id) WHERE retired_at IS NULL");
        $db->statement("CREATE UNIQUE INDEX pxm_grant_active_idx ON {$t['shared_route_grants']} (tenant_key, route_id, consumer_tenant_key) WHERE retired_at IS NULL");
    }

    public static function down(Connection $db): void
    {
        $t = PackageSchema::tables();
        // Rollback предназначен для isolated reinstall: shared memberships отсутствуют в старой схеме.
        $db->statement("DROP TRIGGER pxm_member_grant ON {$t['route_profile_members']}");
        $db->statement("DELETE FROM {$t['route_profile_members']} WHERE shared_grant_id IS NOT NULL");
        $db->statement("ALTER TABLE {$t['route_profile_members']} DROP COLUMN shared_grant_id, DROP COLUMN route_owner_tenant_key CASCADE,
            ADD FOREIGN KEY (tenant_key, route_id) REFERENCES {$t['routes']} (tenant_key, id)");
        $db->statement("DROP TRIGGER pxm_audit_events_immutable ON {$t['audit_events']}");
        $db->statement("DELETE FROM {$t['audit_events']} WHERE event_kind = 'read'");
        // Receipts ссылаются на mutation events, поэтому удаляются раньше shared audit.
        $db->statement("DROP TABLE {$t['catalog_command_receipts']}");
        $db->statement("DELETE FROM {$t['audit_events']} WHERE entity_type = 'shared_route_grants'");
        $db->statement("ALTER TABLE {$t['audit_events']} DROP COLUMN event_kind CASCADE");
        $db->statement("ALTER TABLE {$t['audit_events']} DROP CONSTRAINT pxm_audit_events_entity_type_check,
            ADD CHECK (entity_type IN ('endpoints','routes','route_profiles','targets')),
            ADD CHECK (revision = previous_revision + 1)");
        $db->statement("CREATE TRIGGER pxm_audit_events_immutable BEFORE UPDATE OR DELETE ON {$t['audit_events']} FOR EACH ROW EXECUTE FUNCTION pxm_append_only_guard()");
        $db->statement("DROP TABLE {$t['shared_route_grants']}");
        $db->statement('DROP FUNCTION pxm_member_grant_guard()');
        $db->statement('DROP FUNCTION pxm_grant_revision_guard()');
    }
}
