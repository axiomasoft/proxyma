<?php

declare(strict_types=1);

namespace Proxyma\Filament\Queries;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\Paginator;
use InvalidArgumentException;
use LogicException;
use Packman\Attributes\Api;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Contracts\Authorization\TenantContextResolver;
use Proxyma\Domain\Execution\TransportFailure;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\HealthScope;
use Proxyma\Filament\CatalogBrowser;
use Proxyma\Filament\Internal\FilamentValue;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\DatabaseClock;
use Proxyma\Infrastructure\Database\PackageSchema;

#[Api(since: '1.0')]
class OperationsQuery
{
    public function __construct(private ConnectionScope $scope, private CatalogAccess $access, private TenantContextResolver $tenants) {}

    public function authorize(string $kind, ?string $id = null): void
    {
        $this->access->authorize($this->tenants->resolve()->tenant, "operations.{$kind}.".($id === null ? 'list' : 'read'), new Uuid($id ?? CatalogBrowser::CollectionId));
    }

    /** @return Paginator<int, array<string, mixed>> */
    public function page(string $kind, int $page = 1, HealthScope $healthScope = HealthScope::Route): Paginator
    {
        $this->authorize($kind);
        [$table, $columns] = $this->projection($kind, $healthScope);
        $query = $this->query($table);
        $paginator = $query->orderBy('id')->simplePaginate(25, $columns, 'operationsPage', max(1, min(100000, $page)));
        if (! $paginator instanceof Paginator) {
            throw new LogicException('Expected Paginator instance.');
        }
        $rows = [];
        $now = DatabaseClock::now($this->scope->connection);
        $pageItems = array_values(array_filter($paginator->items(), is_object(...)));
        $windowMap = [];
        if ($kind === 'budgets' && $pageItems !== []) {
            $budgetIds = array_map(static fn (object $item): string => FilamentValue::colStr($item, 'id'), $pageItems);
            $windowsResult = $this->scope->connection->table(PackageSchema::tables()['quota_windows'])
                ->where('tenant_key', $this->tenants->resolve()->tenant->value)->whereIn('budget_id', $budgetIds)
                ->where('starts_at', '<=', $now)->where('ends_at', '>', $now)->get();
            foreach ($windowsResult as $windowRow) {
                $windowMap[FilamentValue::colStr($windowRow, 'budget_id')][] = $windowRow;
            }
        }
        foreach ($pageItems as $row) {
            $rowId = FilamentValue::colStr($row, 'id');
            try {
                $this->authorize($kind, $rowId);
            } catch (AuthorizationDenied) {
                continue;
            }
            $rows[] = match ($kind) {
                'budgets' => $this->budget($row, $windowMap[$rowId] ?? [], $now),
                'health' => $this->health($row, $healthScope),
                default => FilamentValue::assoc(get_object_vars($row)),
            };
        }

        /** @var Paginator<int, array<string, mixed>> $typedPaginator */
        $typedPaginator = $paginator->setCollection(collect($rows));

        return $typedPaginator;
    }

    /** @return array<string, mixed>|null */
    public function find(string $kind, string $id, HealthScope $healthScope = HealthScope::Route): ?array
    {
        $this->authorize($kind);
        $this->authorize($kind, $id);
        [$table, $columns] = $this->projection($kind, $healthScope);
        $row = $this->query($table)->where('id', (new Uuid($id))->value)->first($columns);
        if ($row === null) {
            return null;
        }

        return match ($kind) {
            'health' => $this->health($row, $healthScope),
            'budgets' => $this->budget($row, [], DatabaseClock::now($this->scope->connection)),
            default => FilamentValue::assoc(get_object_vars($row)),
        };
    }

    private function query(string $table): Builder
    {
        $this->scope->assertOutsideTransaction();

        return $this->scope->connection->table(PackageSchema::tables()[$table])->where('tenant_key', $this->tenants->resolve()->tenant->value);
    }

    /** @return array{0: string, 1: list<string>} */
    private function projection(string $kind, HealthScope $scope): array
    {
        return match ($kind) {
            'budgets' => ['budgets', ['id', 'scope_key', 'revision', 'emission_interval_us', 'burst', 'max_inflight', 'inflight', 'tat', 'send_tat', 'cooldown_until', 'hour_limit', 'day_limit', 'quota_policy_key', 'traffic_parent_id']],
            'health' => [$scope->value, ['id', ...($scope !== HealthScope::Target ? ['route_id'] : []), ...($scope !== HealthScope::Route ? ['target_id'] : []), 'state', 'state_version', 'health_epoch', 'reason_code', 'open_until', 'probe_until']],
            'attempts' => ['attempts', ['id', 'operation_id', 'physical_attempt_id', 'status', 'authorized_at', 'completed_at']],
            'audit' => ['audit_events', ['id', 'entity_type', 'entity_id', 'event_kind', 'ability', 'previous_revision', 'revision', 'actor_type', 'actor_id', 'reason', 'created_at']],
            default => throw new InvalidArgumentException('Unknown operations section.'),
        };
    }

    /** @return array<string, mixed> */
    private function health(object $row, HealthScope $scope): array
    {
        $data = FilamentValue::assoc(get_object_vars($row));
        $data['scope'] = $scope->value;
        // reason_code остаётся открытой строкой в storage; UI показывает только код, не произвольный payload.
        $reason = FilamentValue::colNullableStr($row, 'reason_code');
        $data['reason_code'] = $reason === null ? null : (str_starts_with($reason, 'credential_hold') ? 'credential_hold'
            : (TransportFailure::tryFrom($reason) !== null || preg_match('/^http_[1-5][0-9]{2}$/D', $reason) === 1
                || in_array($reason, ['probe_expired', 'waf_rate_signal'], true) ? $reason : 'health_hold'));

        return $data;
    }

    /**
     * @param  list<object>  $windows
     * @return array<string, mixed>
     */
    private function budget(object $row, array $windows, DateTimeImmutable $now): array
    {
        $next = DatabaseClock::micros($now);
        $emissionIntervalUs = FilamentValue::colInt($row, 'emission_interval_us');
        $burst = FilamentValue::colInt($row, 'burst');
        $allowance = $emissionIntervalUs > intdiv(PHP_INT_MAX, max(1, $burst - 1))
            ? PHP_INT_MAX : $emissionIntervalUs * ($burst - 1);
        foreach (['tat', 'send_tat', 'cooldown_until'] as $field) {
            $ts = FilamentValue::colNullableStr($row, $field);
            if ($ts !== null && ! in_array($ts, ['-infinity', 'infinity'], true)) {
                $next = max($next, DatabaseClock::micros(new DateTimeImmutable($ts)) - ($field === 'cooldown_until' ? 0 : $allowance));
            }
        }
        $quotas = [];
        $unknown = false;
        $quotaPolicyKey = FilamentValue::colNullableStr($row, 'quota_policy_key');
        foreach (['hour', 'day'] as $kind) {
            $configured = FilamentValue::colNullableInt($row, $kind.'_limit');
            if ($configured === null) {
                continue;
            }
            $window = array_find(
                $windows,
                static fn (object $w): bool => FilamentValue::colStr($w, 'window_kind') === $kind
                    && FilamentValue::colNullableStr($w, 'policy_key') === $quotaPolicyKey,
            );
            $unknown = $unknown || $window === null || $configured === 0;
            if ($window !== null && FilamentValue::colInt($window, 'spent') + FilamentValue::colInt($window, 'reserved') >= min(FilamentValue::colInt($window, 'quota_limit'), $configured)) {
                $next = max($next, DatabaseClock::micros(new DateTimeImmutable(FilamentValue::colStr($window, 'ends_at'))));
            }
            $quotas[] = ['window' => $kind, 'timezone' => 'UTC', 'limit' => $configured,
                'consumed' => $window === null ? null : FilamentValue::colInt($window, 'spent'),
                'reserved' => $window === null ? null : FilamentValue::colInt($window, 'reserved'),
                'starts_at' => $window === null ? null : $this->utc(FilamentValue::colStr($window, 'starts_at')),
                'ends_at' => $window === null ? null : $this->utc(FilamentValue::colStr($window, 'ends_at'))];
        }
        $scopeKey = FilamentValue::colStr($row, 'scope_key');
        $dimension = explode(':', $scopeKey, 2)[0];
        $inflight = FilamentValue::colInt($row, 'inflight');
        $maxInflight = FilamentValue::colInt($row, 'max_inflight');
        $tat = FilamentValue::colNullableStr($row, 'tat');
        $sendTat = FilamentValue::colNullableStr($row, 'send_tat');
        $cooldownUntil = FilamentValue::colNullableStr($row, 'cooldown_until');

        return ['id' => FilamentValue::colStr($row, 'id'), 'revision' => FilamentValue::colInt($row, 'revision'),
            'scope' => in_array($dimension, ['system', 'tenant', 'panel', 'entity', 'operation', 'origin', 'route', 'route-origin', 'egress-origin', 'traffic', 'probe'], true) ? $dimension : 'custom',
            'scope_fingerprint' => hash('sha256', $scopeKey), 'quotas' => $quotas,
            'emission_interval_us' => $emissionIntervalUs, 'burst' => $burst,
            'inflight' => $inflight, 'max_inflight' => $maxInflight,
            'hour_limit' => FilamentValue::colNullableInt($row, 'hour_limit'),
            'day_limit' => FilamentValue::colNullableInt($row, 'day_limit'),
            'editable' => FilamentValue::col($row, 'traffic_parent_id') === null,
            'next_eligible_at' => $unknown || $inflight >= $maxInflight || $tat === 'infinity' || $sendTat === 'infinity' || $cooldownUntil === 'infinity'
                ? null : DatabaseClock::instant($next)->format('Y-m-d\TH:i:s.u\Z'),
            'cooldown_until' => $cooldownUntil === null ? null : $this->utc($cooldownUntil)];
    }

    private function utc(string $date): ?string
    {
        if (in_array($date, ['-infinity', 'infinity'], true)) {
            return null;
        }

        return new DateTimeImmutable($date)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
