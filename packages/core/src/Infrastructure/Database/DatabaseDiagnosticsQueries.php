<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use DateTimeImmutable;
use Packman\Attributes\Api;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Contracts\Health\DiagnosticsQueries;
use Proxyma\Domain\Execution\SchemaDiagnosticReport;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\CircuitState;
use Proxyma\Domain\Health\HealthSnapshot;
use Proxyma\Domain\Health\TargetAvailability;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Internal\Database\RowValues;

#[Api(since: '1.0')]
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
            $regclass = RowValues::nullableObject($db->selectOne('SELECT to_regclass(?) AS name', [$table]));
            if ($regclass === null || RowValues::colNullableStr($regclass, 'name') === null) {
                $missing[] = $table;
            }
        }
        $p = PackageSchema::prefix();
        $t = PackageSchema::tables();
        $required = [[$t['policy_heads'], "{$p}policy_current_fk"], [$t['budgets'], "{$p}budget_lock_idx"],
            [$t['route_health'], "{$p}credential_hold_identity"], [$t['budgets'], "{$p}cooldown_finite"]];
        $constraints = [];
        foreach ($required as [$table, $name]) {
            if ($db->selectOne('SELECT 1 AS present FROM pg_constraint WHERE conrelid=to_regclass(?) AND conname=? AND convalidated', [$table, $name]) === null) {
                $constraints[] = $name;
            }
        }
        foreach ([[$t['permits'], "{$p}permit_transition"], [$t['attempts'], "{$p}attempt_authorization"],
            [$t['attempts'], "{$p}receipt_guard"], [$t['permits'], "{$p}lease_release_guard"],
            [$t['quota_reservations'], "{$p}quota_ledger"], [$t['quota_windows'], "{$p}quota_window"],
            [$t['audit_events'], "{$p}audit_events_immutable"]] as [$table, $name]) {
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

    /** @param array<string, mixed> $where */
    private function health(string $table, TenantKey $tenant, array $where): ?HealthSnapshot
    {
        $this->scope->assertOutsideTransaction();
        $row = $this->scope->connection->table(PackageSchema::tables()[$table])->where('tenant_key', $tenant->value)->where($where)->first();
        if ($row === null) {
            return null;
        }
        $date = static fn (?string $value): ?DateTimeImmutable => $value === null ? null : new DateTimeImmutable($value);
        $probeToken = RowValues::colNullableStr($row, 'probe_token');
        $availability = RowValues::colNullableStr($row, 'availability');

        return new HealthSnapshot(
            new Uuid(RowValues::colStr($row, 'id')),
            CircuitState::from(RowValues::colStr($row, 'state')),
            RowValues::colInt($row, 'health_epoch'),
            RowValues::colInt($row, 'state_version'),
            RowValues::colInt($row, 'consecutive_failures'),
            RowValues::colInt($row, 'recent_openings'),
            RowValues::colNullableStr($row, 'reason_code'),
            $date(RowValues::colNullableStr($row, 'last_success_at')),
            $date(RowValues::colNullableStr($row, 'last_failure_at')),
            $date(RowValues::colNullableStr($row, 'open_until')),
            $probeToken === null ? null : new Uuid($probeToken),
            $date(RowValues::colNullableStr($row, 'probe_until')),
            $date(RowValues::colNullableStr($row, 'stable_since')),
            $availability !== null ? TargetAvailability::from($availability) : null,
        );
    }
}
