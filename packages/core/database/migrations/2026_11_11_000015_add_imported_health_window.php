<?php

declare(strict_types=1);

use Proxyma\Database\ProxymaMigration;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Eloquent\ImportedHealthSampleRecord;

return new class extends ProxymaMigration
{
    public function up(): void
    {
        $db = $this->db();
        $p = PackageSchema::prefix();
        $table = (new ImportedHealthSampleRecord)->getTable();
        $db->statement("CREATE TABLE {$table} (
            tenant_key text NOT NULL, observation_id uuid NOT NULL, attempt_id uuid NOT NULL,
            application_key text NOT NULL, health_epoch bigint NOT NULL CHECK (health_epoch > 0),
            signal text NOT NULL CHECK (signal IN ('success','failure','maintenance')),
            independence_group text, reducer_allowed boolean NOT NULL, observed_at timestamptz NOT NULL,
            PRIMARY KEY (tenant_key, observation_id, application_key))");
        $db->statement("CREATE INDEX {$p}imported_window ON {$table} (tenant_key, application_key, health_epoch, observed_at)");
        $db->statement("CREATE TRIGGER {$p}imported_window_immutable BEFORE UPDATE OR DELETE ON {$table}
            FOR EACH ROW EXECUTE FUNCTION {$p}append_only_guard()");
    }

    public function down(): void
    {
        $db = $this->db();
        $table = (new ImportedHealthSampleRecord)->getTable();
        $db->statement("DROP TABLE IF EXISTS {$table}");
    }
};
