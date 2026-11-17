<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use Illuminate\Database\Connection;
use Packman\Attributes\Internal;
use Proxyma\Internal\Database\RowValues;
use RuntimeException;

#[Internal]
final class ReservationSchema
{
    public static function up(Connection $db): void
    {
        $t = PackageSchema::tables();
        $db->statement("ALTER TABLE {$t['operations']} ADD COLUMN purpose text NOT NULL DEFAULT 'ordinary'
            CHECK (purpose IN ('ordinary','probe','diagnostic')), ADD COLUMN probe_token uuid,
            ADD CONSTRAINT pxm_operation_probe_token_check CHECK ((purpose = 'probe') = (probe_token IS NOT NULL))");
        $db->statement("ALTER TABLE {$t['permits']} ADD COLUMN reservation_intent text,
            ADD COLUMN reservation_evidence jsonb NOT NULL DEFAULT '{}', ADD COLUMN reservation_limits jsonb NOT NULL DEFAULT '{}',
            ADD CONSTRAINT pxm_permit_reservation_intent_check CHECK (reservation_intent IS NULL OR reservation_intent ~ '^[0-9a-f]{64}$')");
        $db->statement("ALTER TABLE {$t['reservations']} ADD COLUMN budget_tenant_key text");
        $db->statement("UPDATE {$t['reservations']} SET budget_tenant_key = tenant_key");
        $foreign = $db->select("SELECT conname FROM pg_constraint WHERE conrelid = ?::regclass AND contype = 'f'
            AND confrelid IN (?::regclass, ?::regclass)", [$t['reservations'], $t['budgets'], $t['quota_windows']]);
        foreach ($foreign as $key) {
            $db->statement("ALTER TABLE {$t['reservations']} DROP CONSTRAINT ".$db->getQueryGrammar()->wrap(RowValues::colStr(RowValues::object($key), 'conname')));
        }
        $db->statement('CREATE FUNCTION pxm_reservation_owner_default() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN NEW.budget_tenant_key := COALESCE(NEW.budget_tenant_key, NEW.tenant_key); RETURN NEW; END; $$');
        $db->statement("CREATE TRIGGER pxm_reservation_owner_default BEFORE INSERT ON {$t['reservations']}
                FOR EACH ROW EXECUTE FUNCTION pxm_reservation_owner_default()");
        $db->statement("ALTER TABLE {$t['reservations']} ALTER COLUMN budget_tenant_key SET NOT NULL,
            ADD CONSTRAINT pxm_reservation_owner_check CHECK (budget_tenant_key = tenant_key OR budget_tenant_key = 'system'),
            ADD CONSTRAINT pxm_reservation_budget_owner_fk FOREIGN KEY (budget_tenant_key, budget_id) REFERENCES {$t['budgets']} (tenant_key, id),
            ADD CONSTRAINT pxm_reservation_window_owner_fk FOREIGN KEY (budget_tenant_key, quota_window_id, budget_id) REFERENCES {$t['quota_windows']} (tenant_key, id, budget_id)");
        $db->statement("CREATE FUNCTION pxm_permit_reservation_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF OLD.reservation_intent IS NOT NULL AND ROW(NEW.tenant_key, NEW.operation_id, NEW.demand_id, NEW.route_id, NEW.target_id,
                    NEW.physical_attempt_id, NEW.wire_fingerprint, NEW.cost, NEW.not_before, NEW.expires_at, NEW.reservation_intent,
                    NEW.reservation_evidence, NEW.reservation_limits) IS DISTINCT FROM ROW(OLD.tenant_key, OLD.operation_id, OLD.demand_id,
                    OLD.route_id, OLD.target_id, OLD.physical_attempt_id, OLD.wire_fingerprint, OLD.cost, OLD.not_before, OLD.expires_at,
                    OLD.reservation_intent, OLD.reservation_evidence, OLD.reservation_limits) THEN
                    RAISE EXCEPTION 'Reservation intent is immutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END; \$\$");
        $db->statement("CREATE TRIGGER pxm_permit_reservation_guard BEFORE UPDATE ON {$t['permits']}
                FOR EACH ROW EXECUTE FUNCTION pxm_permit_reservation_guard()");
        $db->statement("CREATE FUNCTION pxm_reservation_reference_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF EXISTS (SELECT 1 FROM {$t['permits']} WHERE id = OLD.permit_id AND reservation_intent IS NOT NULL) THEN
                    IF ROW(NEW.tenant_key, NEW.permit_id, NEW.budget_tenant_key, NEW.budget_id, NEW.quota_window_id, NEW.cost)
                        IS DISTINCT FROM ROW(OLD.tenant_key, OLD.permit_id, OLD.budget_tenant_key, OLD.budget_id, OLD.quota_window_id, OLD.cost) THEN
                        RAISE EXCEPTION 'Reservation reference is immutable' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.state IS DISTINCT FROM OLD.state AND NOT ((OLD.state = 'reserved' AND NEW.state IN ('spent','released'))
                        OR (OLD.state = 'spent' AND NEW.state = 'released')) THEN
                        RAISE EXCEPTION 'Reservation lease cannot be revived' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END; \$\$");
        $db->statement("CREATE TRIGGER pxm_reservation_reference_guard BEFORE UPDATE ON {$t['reservations']}
                FOR EACH ROW EXECUTE FUNCTION pxm_reservation_reference_guard()");
    }

    public static function down(Connection $db): void
    {
        $t = PackageSchema::tables();
        if ($db->table($t['permits'])->whereNotNull('reservation_intent')->exists()
            || $db->table($t['reservations'])->whereColumn('budget_tenant_key', '<>', 'tenant_key')->exists()
            || $db->table($t['operations'])->where('purpose', '<>', 'ordinary')->exists()) {
            throw new RuntimeException('Cannot remove durable reservation history.');
        }
        $db->statement("DROP TRIGGER pxm_permit_reservation_guard ON {$t['permits']}");
        $db->statement('DROP FUNCTION pxm_permit_reservation_guard()');
        $db->statement("DROP TRIGGER pxm_reservation_owner_default ON {$t['reservations']}");
        $db->statement('DROP FUNCTION pxm_reservation_owner_default()');
        $db->statement("DROP TRIGGER pxm_reservation_reference_guard ON {$t['reservations']}");
        $db->statement('DROP FUNCTION pxm_reservation_reference_guard()');
        $db->statement("ALTER TABLE {$t['reservations']} DROP CONSTRAINT pxm_reservation_owner_check,
            DROP CONSTRAINT pxm_reservation_budget_owner_fk, DROP CONSTRAINT pxm_reservation_window_owner_fk, DROP COLUMN budget_tenant_key,
            ADD FOREIGN KEY (tenant_key, budget_id) REFERENCES {$t['budgets']} (tenant_key, id),
            ADD FOREIGN KEY (tenant_key, quota_window_id, budget_id) REFERENCES {$t['quota_windows']} (tenant_key, id, budget_id)");
        $db->statement("ALTER TABLE {$t['permits']} DROP CONSTRAINT pxm_permit_reservation_intent_check,
            DROP COLUMN reservation_intent, DROP COLUMN reservation_evidence, DROP COLUMN reservation_limits");
        $db->statement("ALTER TABLE {$t['operations']} DROP CONSTRAINT pxm_operation_probe_token_check, DROP COLUMN purpose, DROP COLUMN probe_token");
    }
}
