<?php

declare(strict_types=1);

use Proxyma\Database\ProxymaMigration;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Eloquent\ObservationApplicationRecord;
use Proxyma\Infrastructure\Eloquent\ObservationRecord;

return new class extends ProxymaMigration
{
    public function up(): void
    {
        $db = $this->db();
        $p = PackageSchema::prefix();
        $observations = (new ObservationRecord)->getTable();
        $applications = (new ObservationApplicationRecord)->getTable();
        $db->statement("ALTER TABLE {$applications} ADD COLUMN reducer_allowed boolean NOT NULL DEFAULT true");
        $db->statement("CREATE UNIQUE INDEX {$p}observation_kind_once ON {$observations} (tenant_key, attempt_id, classification)");
        $db->statement("CREATE TRIGGER {$p}observation_immutable BEFORE UPDATE OR DELETE ON {$observations} FOR EACH ROW EXECUTE FUNCTION {$p}append_only_guard()");
        $db->statement("CREATE TRIGGER {$p}application_immutable BEFORE UPDATE OR DELETE ON {$applications} FOR EACH ROW EXECUTE FUNCTION {$p}append_only_guard()");
    }

    public function down(): void
    {
        $db = $this->db();
        $p = PackageSchema::prefix();
        $observations = (new ObservationRecord)->getTable();
        $applications = (new ObservationApplicationRecord)->getTable();
        $db->statement("DROP TRIGGER {$p}application_immutable ON {$applications}");
        $db->statement("DROP TRIGGER {$p}observation_immutable ON {$observations}");
        $db->statement("DROP INDEX {$p}observation_kind_once");
        $db->statement("ALTER TABLE {$applications} DROP COLUMN reducer_allowed");
    }
};
