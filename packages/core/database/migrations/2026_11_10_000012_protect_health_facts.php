<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Proxyma\Infrastructure\Eloquent\ObservationApplicationRecord;
use Proxyma\Infrastructure\Eloquent\ObservationRecord;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('proxyma.connection', 'pgsql'));
        $observations = (new ObservationRecord)->getTable();
        $applications = (new ObservationApplicationRecord)->getTable();
        $db->statement("ALTER TABLE {$applications} ADD COLUMN reducer_allowed boolean NOT NULL DEFAULT true");
        $db->statement("CREATE UNIQUE INDEX pxm_observation_kind_once ON {$observations} (tenant_key, attempt_id, classification)");
        $db->statement("CREATE TRIGGER pxm_observation_immutable BEFORE UPDATE OR DELETE ON {$observations} FOR EACH ROW EXECUTE FUNCTION pxm_append_only_guard()");
        $db->statement("CREATE TRIGGER pxm_application_immutable BEFORE UPDATE OR DELETE ON {$applications} FOR EACH ROW EXECUTE FUNCTION pxm_append_only_guard()");
    }

    public function down(): void
    {
        $db = DB::connection(config('proxyma.connection', 'pgsql'));
        $observations = (new ObservationRecord)->getTable();
        $applications = (new ObservationApplicationRecord)->getTable();
        $db->statement("DROP TRIGGER pxm_application_immutable ON {$applications}");
        $db->statement("DROP TRIGGER pxm_observation_immutable ON {$observations}");
        $db->statement('DROP INDEX pxm_observation_kind_once');
        $db->statement("ALTER TABLE {$applications} DROP COLUMN reducer_allowed");
    }
};
