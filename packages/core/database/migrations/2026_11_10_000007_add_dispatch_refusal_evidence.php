<?php

declare(strict_types=1);

use Proxyma\Database\ProxymaMigration;
use Proxyma\Infrastructure\Database\PackageSchema;

return new class extends ProxymaMigration
{
    public function up(): void
    {
        $db = $this->db();
        $p = PackageSchema::prefix();
        $table = PackageSchema::tables()['attempts'];
        $db->statement("ALTER TABLE {$table} ADD COLUMN dispatch_outcome text NOT NULL DEFAULT 'unknown'
            CHECK (dispatch_outcome IN ('unknown','not_dispatched')), ADD COLUMN dispatch_refused_at timestamptz,
            ADD CHECK ((dispatch_outcome='not_dispatched') = (dispatch_refused_at IS NOT NULL))");
        $db->unprepared("CREATE FUNCTION {$p}attempt_authorization_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF ROW(NEW.id, NEW.tenant_key, NEW.operation_id, NEW.permit_id, NEW.physical_attempt_id, NEW.wire_fingerprint,
                    NEW.authorized_at, NEW.snapshot) IS DISTINCT FROM ROW(OLD.id, OLD.tenant_key, OLD.operation_id, OLD.permit_id,
                    OLD.physical_attempt_id, OLD.wire_fingerprint, OLD.authorized_at, OLD.snapshot)
                    OR (OLD.receipt_id IS NOT NULL AND NEW.receipt_id IS DISTINCT FROM OLD.receipt_id)
                    OR (OLD.dispatch_outcome='not_dispatched' AND ROW(NEW.dispatch_outcome, NEW.dispatch_refused_at)
                        IS DISTINCT FROM ROW(OLD.dispatch_outcome, OLD.dispatch_refused_at)) THEN
                    RAISE EXCEPTION 'immutable attempt authorization' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END \$\$;
            CREATE TRIGGER {$p}attempt_authorization BEFORE UPDATE ON {$table}
            FOR EACH ROW EXECUTE FUNCTION {$p}attempt_authorization_guard()");
    }

    public function down(): void
    {
        $db = $this->db();
        $p = PackageSchema::prefix();
        $table = PackageSchema::tables()['attempts'];
        if ($db->table($table)->whereNotNull('dispatch_refused_at')->exists()) {
            throw new LogicException('Dispatch refusal history prevents rollback.');
        }
        $db->statement("ALTER TABLE {$table} DROP COLUMN dispatch_outcome, DROP COLUMN dispatch_refused_at");
        $db->statement("DROP TRIGGER {$p}attempt_authorization ON {$table}");
        $db->statement("DROP FUNCTION {$p}attempt_authorization_guard()");
    }
};
