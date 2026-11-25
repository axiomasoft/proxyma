<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use Illuminate\Database\Connection;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final class RecoverySchema
{
    public static function up(Connection $db): void
    {
        $p = PackageSchema::prefix($db);
        $t = PackageSchema::tables($db);
        $cursor = PackageSchema::table('reconcile_cursors', $db);
        $db->statement("CREATE TABLE {$cursor} (id uuid PRIMARY KEY DEFAULT gen_random_uuid(),tenant_key text NOT NULL UNIQUE, after_id uuid, lease_token uuid,
            lease_until timestamptz CHECK (isfinite(lease_until)), CHECK ((lease_token IS NULL) = (lease_until IS NULL)))");
        $artifact = PackageSchema::table('temporary_artifacts', $db);
        $db->statement("ALTER TABLE {$t['permits']} ADD CONSTRAINT {$p}permit_artifact_identity UNIQUE (tenant_key,id,operation_id)");
        $db->statement("CREATE TABLE {$artifact} (id uuid PRIMARY KEY,tenant_key text NOT NULL,operation_id uuid NOT NULL,permit_id uuid NOT NULL,
            ownership_token text NOT NULL CHECK (ownership_token ~ '^[0-9a-f]{64}$'),created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
            delete_pending boolean NOT NULL DEFAULT false,
            FOREIGN KEY (tenant_key,permit_id,operation_id) REFERENCES {$t['permits']} (tenant_key,id,operation_id))");
        $db->statement("ALTER TABLE {$t['permits']} ADD COLUMN lease_released_at timestamptz");
        $db->statement("ALTER TABLE {$t['attempts']} ADD COLUMN receipt_key text CHECK (receipt_key ~ '^[0-9a-f]{64}$'),
            ADD COLUMN receipt_digest text CHECK (receipt_digest ~ '^[0-9a-f]{64}$'),
            ADD COLUMN result_digest text CHECK (result_digest ~ '^[0-9a-f]{64}$'), ADD COLUMN result_summary jsonb,
            ADD CONSTRAINT {$p}receipt_pair CHECK ((receipt_key IS NULL AND receipt_digest IS NULL AND result_digest IS NULL)
                OR (receipt_key IS NOT NULL AND receipt_digest IS NOT NULL AND result_digest IS NOT NULL)),
            ADD CONSTRAINT {$p}receipt_key_unique UNIQUE (tenant_key,receipt_key)");
        $db->statement("CREATE FUNCTION {$p}receipt_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'authorization history cannot be deleted' USING ERRCODE='23514'; END IF;
                IF ROW(NEW.tenant_key,NEW.operation_id,NEW.permit_id,NEW.physical_attempt_id,NEW.wire_fingerprint,NEW.authorized_at,NEW.snapshot)
                    IS DISTINCT FROM ROW(OLD.tenant_key,OLD.operation_id,OLD.permit_id,OLD.physical_attempt_id,OLD.wire_fingerprint,OLD.authorized_at,OLD.snapshot)
                    OR (OLD.receipt_key IS NOT NULL AND ROW(NEW.receipt_key,NEW.receipt_digest,NEW.result_digest,NEW.result_summary,NEW.receipt_id,NEW.completed_at,NEW.status)
                        IS DISTINCT FROM ROW(OLD.receipt_key,OLD.receipt_digest,OLD.result_digest,OLD.result_summary,OLD.receipt_id,OLD.completed_at,OLD.status)) THEN
                    RAISE EXCEPTION 'attempt fact is immutable' USING ERRCODE='23514';
                END IF;
                IF NEW.status<>OLD.status AND NOT ((OLD.status='authorized' AND NEW.status IN ('completed','unknown_after_authorization'))
                    OR (OLD.status='unknown_after_authorization' AND NEW.status='completed')) THEN
                    RAISE EXCEPTION 'invalid attempt lifecycle' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; \$\$");
        $db->statement("CREATE TRIGGER {$p}receipt_guard BEFORE UPDATE OR DELETE ON {$t['attempts']} FOR EACH ROW EXECUTE FUNCTION {$p}receipt_guard()");
        $db->statement("CREATE FUNCTION {$p}lease_release_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF OLD.lease_released_at IS NOT NULL AND NEW.lease_released_at IS DISTINCT FROM OLD.lease_released_at THEN
                    RAISE EXCEPTION 'lease release is irreversible' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END; \$\$");
        $db->statement("CREATE TRIGGER {$p}lease_release_guard BEFORE UPDATE ON {$t['permits']} FOR EACH ROW EXECUTE FUNCTION {$p}lease_release_guard()");
        $db->statement("CREATE INDEX {$p}recovery_permit_idx ON {$t['permits']} (operation_id,expires_at) WHERE lease_released_at IS NULL");
        $db->statement("CREATE OR REPLACE FUNCTION {$p}demand_scope_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF TG_OP='DELETE' AND EXISTS (SELECT 1 FROM {$t['demands']} d JOIN {$t['operations']} o ON o.id=d.operation_id
                    WHERE d.id=OLD.demand_id AND d.tenant_key=OLD.tenant_key AND d.state IN ('expired','cancelled','completed')
                    AND o.status<>'active' AND o.deadline<clock_timestamp()
                    AND NOT EXISTS (SELECT 1 FROM {$t['permits']} p WHERE p.demand_id=d.id)) THEN
                    RETURN OLD;
                END IF;
                RAISE EXCEPTION 'Demand conflict set is immutable' USING ERRCODE='23514';
            END; \$\$");
    }

    public static function down(Connection $db): void
    {
        $p = PackageSchema::prefix($db);
        $t = PackageSchema::tables($db);
        if ($db->table($t['attempts'])->whereNotNull('receipt_key')->exists() || $db->table($t['permits'])->whereNotNull('lease_released_at')->exists()
            || PackageSchema::query($db, 'temporary_artifacts')->exists()) {
            throw new LogicException('Cannot remove recovery history.');
        }
        $db->statement("DROP TRIGGER {$p}receipt_guard ON {$t['attempts']}");
        $db->statement("DROP FUNCTION {$p}receipt_guard()");
        $db->statement("DROP INDEX {$p}recovery_permit_idx");
        $db->statement("DROP TRIGGER {$p}lease_release_guard ON {$t['permits']}");
        $db->statement("DROP FUNCTION {$p}lease_release_guard()");
        $db->statement("CREATE OR REPLACE FUNCTION {$p}demand_scope_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN RAISE EXCEPTION 'Demand conflict set is immutable' USING ERRCODE='23514'; END; \$\$");
        $db->statement("ALTER TABLE {$t['attempts']} DROP CONSTRAINT {$p}receipt_key_unique, DROP CONSTRAINT {$p}receipt_pair,
            DROP COLUMN receipt_key, DROP COLUMN receipt_digest, DROP COLUMN result_digest, DROP COLUMN result_summary");
        $db->statement("ALTER TABLE {$t['permits']} DROP COLUMN lease_released_at");
        $db->statement('DROP TABLE '.PackageSchema::table('reconcile_cursors', $db));
        $db->statement('DROP TABLE '.PackageSchema::table('temporary_artifacts', $db));
        $db->statement("ALTER TABLE {$t['permits']} DROP CONSTRAINT {$p}permit_artifact_identity");
    }
}
