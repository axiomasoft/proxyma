<?php

declare(strict_types=1);

namespace Proxyma\Application\Diagnostics;

use DateTimeImmutable;
use InvalidArgumentException;
use Packman\Attributes\Api;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\DatabaseClock;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Telemetry\OperationsTelemetry;
use Proxyma\Internal\Database\RowValues;

#[Api(since: '1.0')]
final readonly class OperationsMetrics
{
    public function __construct(private ConnectionScope $scope, private CatalogAccess $access, private OperationsTelemetry $telemetry) {}

    /** @return array<string, mixed> */
    public function snapshot(TenantKey $tenant, int $limit = 32): array
    {
        if ($limit < 1 || $limit > 64) {
            throw new InvalidArgumentException('Metric sample must be 1..64.');
        }
        $this->scope->assertOutsideTransaction();
        $this->access->authorize($tenant, 'diagnostics.metrics', new Uuid('00000000-0000-0000-0000-000000000001'));
        $db = $this->scope->connection;
        $t = PackageSchema::tables($db);
        $demands = $db->table($t['demands'])->where('tenant_key', $tenant->value)->where('state', 'active')->orderBy('created_at')->orderBy('id')->limit($limit)->get();
        $permits = $db->table($t['permits'].' as p')->join($t['demands'].' as d', 'd.id', '=', 'p.demand_id')
            ->where('p.tenant_key', $tenant->value)->orderByDesc('p.created_at')->orderBy('p.id')->limit($limit)
            ->get(['p.state', 'p.authorized_at', 'p.lease_released_at', 'd.created_at']);
        $due = $db->table($t['permits'])->where('tenant_key', $tenant->value)->whereNull('lease_released_at')
            ->whereRaw('expires_at <= clock_timestamp()')->orderBy('expires_at')->limit(1)->value('expires_at');
        $now = DatabaseClock::now($db);
        $seconds = static fn (string $date): float|int => max(0, (DatabaseClock::micros($now) - DatabaseClock::micros(new DateTimeImmutable($date))) / 1_000_000);
        $waits = $permits->filter(static fn (object $p): bool => RowValues::col($p, 'authorized_at') !== null)
            ->map(static fn (object $p): float|int => max(0,
                (DatabaseClock::micros(new DateTimeImmutable(RowValues::colStr($p, 'authorized_at')))
                    - DatabaseClock::micros(new DateTimeImmutable(RowValues::colStr($p, 'created_at')))) / 1_000_000));
        $firstDemand = $demands->first();
        $metrics = [
            $this->telemetry->sample('queue_age_seconds', $firstDemand === null ? 0 : $seconds(RowValues::colStr($firstDemand, 'created_at'))),
            $this->telemetry->sample('admission_wait_seconds', RowValues::float($waits->max() ?? 0)),
            $this->telemetry->sample('claim', $permits->whereNotNull('authorized_at')->count()),
            $this->telemetry->sample('completed', $permits->where('state', 'completed')->count()),
            $this->telemetry->sample('unknown', $permits->where('state', 'unknown_after_authorization')->count()),
            $this->telemetry->sample('lease_inflight', $permits->whereNull('lease_released_at')->count()),
            $this->telemetry->sample('reconcile_lag_seconds', $due === null ? 0 : $seconds(RowValues::str($due))),
        ];

        return ['as_of' => $now->format('Y-m-d\TH:i:s.uP'), 'sample_limit' => $limit,
            'sampled' => true, 'lease_inflight_is_physical_sockets' => false, 'metrics' => $metrics];
    }
}
