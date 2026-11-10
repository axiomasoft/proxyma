<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use DateTimeImmutable;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Contracts\DiagnosticsQueries;
use Proxyma\Domain\Execution\SchemaDiagnosticReport;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\CircuitState;
use Proxyma\Domain\Health\HealthSnapshot;
use Proxyma\Domain\Health\TargetAvailability;
use Proxyma\Domain\Policy\TenantKey;

final readonly class DatabaseDiagnosticsQueries implements DiagnosticsQueries
{
    public function __construct(private ConnectionScope $scope, private CatalogAccess $access) {}

    public function verifySchemaCapabilities(): SchemaDiagnosticReport
    {
        $this->scope->assertOutsideTransaction();
        $db = $this->scope->connection;
        if ($db->getDriverName() !== 'pgsql') {
            return new SchemaDiagnosticReport(false, $db->getDriverName());
        }
        $missing = [];
        foreach (PackageSchema::tables() as $table) {
            if ($db->selectOne('SELECT to_regclass(?) AS name', [$table])->name === null) {
                $missing[] = $table;
            }
        }
        $t = PackageSchema::tables();
        $required = [[$t['policy_heads'], 'pxm_policy_current_fk'], [$t['budgets'], 'pxm_budget_lock_idx'],
            [$t['route_health'], 'pxm_credential_hold_identity'], [$t['budgets'], 'pxm_cooldown_finite']];
        $constraints = [];
        foreach ($required as [$table, $name]) {
            if ($db->selectOne('SELECT 1 AS present FROM pg_constraint WHERE conrelid=to_regclass(?) AND conname=? AND convalidated', [$table, $name]) === null) {
                $constraints[] = $name;
            }
        }
        foreach ([[$t['permits'], 'pxm_permit_transition'], [$t['attempts'], 'pxm_attempt_authorization'],
            [$t['attempts'], 'pxm_receipt_guard'], [$t['permits'], 'pxm_lease_release_guard'],
            [$t['quota_reservations'], 'pxm_quota_ledger'], [$t['quota_windows'], 'pxm_quota_window'],
            [$t['audit_events'], 'pxm_audit_events_immutable']] as [$table, $name]) {
            if ($db->selectOne("SELECT 1 AS present FROM pg_trigger WHERE tgrelid=to_regclass(?) AND tgname=? AND tgenabled IN ('O','A')", [$table, $name]) === null) {
                $constraints[] = $name;
            }
        }

        return new SchemaDiagnosticReport($missing === [] && $constraints === [], 'pgsql', $missing, $constraints);
    }

    public function inspectRouteHealth(TenantKey $tenant, Uuid $routeId, ?Uuid $targetId = null): ?HealthSnapshot
    {
        $this->access->authorize($tenant, 'catalog.route.read', $routeId);
        if ($targetId !== null) {
            $this->access->authorize($tenant, 'catalog.target.read', $targetId);
        }

        return $this->health($targetId === null ? 'route_health' : 'route_target_health', $tenant,
            ['route_id' => $routeId->value] + ($targetId === null ? [] : ['target_id' => $targetId->value]));
    }

    public function inspectTargetHealth(TenantKey $tenant, Uuid $targetId): ?HealthSnapshot
    {
        $this->access->authorize($tenant, 'catalog.target.read', $targetId);

        return $this->health('target_health', $tenant, ['target_id' => $targetId->value]);
    }

    private function health(string $table, TenantKey $tenant, array $where): ?HealthSnapshot
    {
        $this->scope->assertOutsideTransaction();
        $row = $this->scope->connection->table(PackageSchema::tables()[$table])->where('tenant_key', $tenant->value)->where($where)->first();
        if ($row === null) {
            return null;
        }
        $date = static fn ($value) => $value === null ? null : new DateTimeImmutable($value);

        return new HealthSnapshot(new Uuid($row->id), CircuitState::from($row->state), (int) $row->health_epoch, (int) $row->state_version,
            (int) $row->consecutive_failures, (int) $row->recent_openings, $row->reason_code,
            $date($row->last_success_at), $date($row->last_failure_at), $date($row->open_until),
            $row->probe_token === null ? null : new Uuid($row->probe_token), $date($row->probe_until), $date($row->stable_since),
            isset($row->availability) ? TargetAvailability::from($row->availability) : null);
    }
}
