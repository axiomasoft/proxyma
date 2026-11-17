<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use Illuminate\Database\Connection;
use Packman\Attributes\Internal;
use RuntimeException;

#[Internal]
final class DemandQueueSchema
{
    public static function up(Connection $db): void
    {
        $t = PackageSchema::tables();
        $db->statement("ALTER TABLE {$t['operations']} ADD COLUMN external_operation_key text,
            ADD COLUMN parent_attempt_id uuid, ADD COLUMN intent_hash text,
            ADD CONSTRAINT pxm_operation_correlation_check CHECK (external_operation_key IS NULL OR
                (octet_length(external_operation_key) BETWEEN 1 AND 255 AND external_operation_key !~ '[[:space:]]')),
            ADD CONSTRAINT pxm_operation_intent_check CHECK (intent_hash IS NULL OR intent_hash ~ '^[0-9a-f]{64}$')");
        $db->statement("ALTER TABLE {$t['demands']} ADD COLUMN physical_attempt_id uuid,
            ADD COLUMN wire_fingerprint text, ADD COLUMN intent_hash text, ADD COLUMN scope_hash text, ADD COLUMN expires_at timestamptz, ADD COLUMN admitted_at timestamptz,
            ADD CONSTRAINT pxm_demand_identity_check CHECK ((physical_attempt_id IS NULL AND wire_fingerprint IS NULL AND intent_hash IS NULL AND scope_hash IS NULL AND expires_at IS NULL)
                OR (physical_attempt_id IS NOT NULL AND wire_fingerprint ~ '^[0-9a-f]{64}$' AND wire_fingerprint IS NOT NULL
                    AND intent_hash ~ '^[0-9a-f]{64}$' AND intent_hash IS NOT NULL AND scope_hash ~ '^[0-9a-f]{64}$' AND scope_hash IS NOT NULL AND expires_at IS NOT NULL
                    AND isfinite(expires_at) AND expires_at <= deadline)),
            ADD CONSTRAINT pxm_demand_physical_unique UNIQUE (tenant_key, operation_id, physical_attempt_id)");
        $db->statement("CREATE TABLE {$t['demand_scopes']} (
            id uuid PRIMARY KEY DEFAULT gen_random_uuid(), tenant_key text NOT NULL,
            demand_id uuid NOT NULL, budget_tenant_key text NOT NULL, budget_id uuid NOT NULL,
            created_at timestamptz NOT NULL DEFAULT clock_timestamp(), updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
            CHECK (budget_tenant_key = tenant_key OR budget_tenant_key = 'system'),
            FOREIGN KEY (tenant_key, demand_id) REFERENCES {$t['demands']} (tenant_key, id),
            FOREIGN KEY (budget_tenant_key, budget_id) REFERENCES {$t['budgets']} (tenant_key, id),
            UNIQUE (tenant_key, demand_id, budget_tenant_key, budget_id))");
        $db->statement("CREATE INDEX pxm_demand_scope_conflict_idx ON {$t['demand_scopes']} (budget_id, demand_id)");
        $db->statement("CREATE INDEX pxm_demand_fifo_active_idx ON {$t['demands']} (sequence, id) WHERE physical_attempt_id IS NOT NULL AND state = 'active'");
        $db->statement("CREATE FUNCTION pxm_demand_identity_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF OLD.physical_attempt_id IS NOT NULL AND
                    ROW(NEW.tenant_key, NEW.operation_id, NEW.profile_id, NEW.target_id, NEW.physical_attempt_id, NEW.wire_fingerprint,
                        NEW.intent_hash, NEW.scope_hash, NEW.sequence, NEW.deadline, NEW.expires_at, NEW.purpose, NEW.cost)
                    IS DISTINCT FROM
                    ROW(OLD.tenant_key, OLD.operation_id, OLD.profile_id, OLD.target_id, OLD.physical_attempt_id, OLD.wire_fingerprint,
                        OLD.intent_hash, OLD.scope_hash, OLD.sequence, OLD.deadline, OLD.expires_at, OLD.purpose, OLD.cost) THEN
                    RAISE EXCEPTION 'Demand identity and lifetime are immutable' USING ERRCODE = '23514';
                END IF;
                IF OLD.physical_attempt_id IS NOT NULL AND OLD.state IS DISTINCT FROM NEW.state AND NOT
                    ((OLD.state = 'active' AND NEW.state IN ('admitted','cancelled','expired')) OR
                     (OLD.state = 'admitted' AND NEW.state IN ('completed','cancelled','expired'))) THEN
                    RAISE EXCEPTION 'Demand cannot reenter the queue' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END; \$\$");
        $db->statement("CREATE TRIGGER pxm_demand_identity_guard BEFORE UPDATE ON {$t['demands']}
                FOR EACH ROW EXECUTE FUNCTION pxm_demand_identity_guard()");
        $db->statement("CREATE FUNCTION pxm_demand_scope_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN RAISE EXCEPTION 'Demand conflict set is immutable' USING ERRCODE = '23514'; END; \$\$");
        $db->statement("CREATE TRIGGER pxm_demand_scope_guard BEFORE UPDATE OR DELETE ON {$t['demand_scopes']}
                FOR EACH ROW EXECUTE FUNCTION pxm_demand_scope_guard()");
        $db->statement("CREATE FUNCTION pxm_demand_scope_exact_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE demand uuid; expected text; actual text;
            BEGIN
                IF TG_TABLE_NAME = '{$t['demands']}' THEN demand := NEW.id; ELSE demand := NEW.demand_id; END IF;
                SELECT scope_hash INTO expected FROM {$t['demands']} WHERE id = demand;
                IF expected IS NOT NULL THEN
                    SELECT encode(sha256(convert_to(COALESCE(string_agg(budget_tenant_key || ':' || budget_id::text, ','
                        ORDER BY (budget_tenant_key || ':' || budget_id::text) COLLATE \"C\"), ''), 'UTF8')), 'hex')
                        INTO actual FROM {$t['demand_scopes']} WHERE demand_id = demand;
                    IF actual <> expected THEN RAISE EXCEPTION 'Demand conflict set differs from sealed scopes' USING ERRCODE = '23514'; END IF;
                END IF;
                RETURN NEW;
            END; \$\$");
        $db->statement("CREATE CONSTRAINT TRIGGER pxm_demand_scope_exact_guard AFTER INSERT ON {$t['demands']}
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION pxm_demand_scope_exact_guard()");
        $db->statement("CREATE CONSTRAINT TRIGGER pxm_scope_exact_guard AFTER INSERT ON {$t['demand_scopes']}
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION pxm_demand_scope_exact_guard()");
    }

    public static function down(Connection $db): void
    {
        $t = PackageSchema::tables();
        if ($db->table($t['demands'])->whereNotNull('physical_attempt_id')->exists()
            || $db->table($t['operations'])->whereNotNull('intent_hash')->exists()) {
            throw new RuntimeException('Cannot remove durable operation/demand identity history.');
        }
        $db->statement("DROP TABLE {$t['demand_scopes']}");
        $db->statement("DROP TRIGGER pxm_demand_identity_guard ON {$t['demands']}");
        $db->statement("DROP TRIGGER pxm_demand_scope_exact_guard ON {$t['demands']}");
        $db->statement('DROP FUNCTION pxm_demand_identity_guard()');
        $db->statement('DROP FUNCTION pxm_demand_scope_guard()');
        $db->statement('DROP FUNCTION pxm_demand_scope_exact_guard()');
        $db->statement('DROP INDEX pxm_demand_fifo_active_idx');
        $db->statement("ALTER TABLE {$t['demands']} DROP CONSTRAINT pxm_demand_identity_check, DROP CONSTRAINT pxm_demand_physical_unique,
            DROP COLUMN physical_attempt_id, DROP COLUMN wire_fingerprint, DROP COLUMN intent_hash, DROP COLUMN scope_hash, DROP COLUMN expires_at, DROP COLUMN admitted_at");
        $db->statement("ALTER TABLE {$t['operations']} DROP CONSTRAINT pxm_operation_correlation_check, DROP CONSTRAINT pxm_operation_intent_check,
            DROP COLUMN external_operation_key, DROP COLUMN parent_attempt_id, DROP COLUMN intent_hash");
    }
}
