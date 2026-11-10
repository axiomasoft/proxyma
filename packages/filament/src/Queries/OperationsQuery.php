<?php

declare(strict_types=1);

namespace Proxyma\Filament\Queries;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\Paginator;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Contracts\TenantContextResolver;
use Proxyma\Domain\Execution\TransportFailure;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\HealthScope;
use Proxyma\Filament\CatalogBrowser;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\DatabaseClock;
use Proxyma\Infrastructure\Database\PackageSchema;

class OperationsQuery
{
    public function __construct(private ConnectionScope $scope, private CatalogAccess $access, private TenantContextResolver $tenants) {}

    public function authorize(string $kind, ?string $id = null): void
    {
        $this->access->authorize($this->tenants->resolve()->tenant, "operations.{$kind}.".($id === null ? 'list' : 'read'), new Uuid($id ?? CatalogBrowser::CollectionId));
    }

    public function page(string $kind, int $page = 1, HealthScope $healthScope = HealthScope::Route): Paginator
    {
        $this->authorize($kind);
        [$table, $columns] = $this->projection($kind, $healthScope);
        $query = $this->query($table);
        $paginator = $query->orderBy('id')->simplePaginate(25, $columns, 'operationsPage', max(1, min(100000, $page)));
        $rows = [];
        $now = DatabaseClock::now($this->scope->connection);
        $windows = $kind === 'budgets' ? $this->scope->connection->table(PackageSchema::tables()['quota_windows'])
            ->where('tenant_key', $this->tenants->resolve()->tenant->value)->whereIn('budget_id', array_column($paginator->items(), 'id'))
            ->where('starts_at', '<=', $now)->where('ends_at', '>', $now)->get()->groupBy('budget_id') : collect();
        foreach ($paginator->items() as $row) {
            try {
                $this->authorize($kind, $row->id);
            } catch (AuthorizationDenied) {
                continue;
            }
            $rows[] = match ($kind) {
                'budgets' => $this->budget($row, $windows->get($row->id, collect())->all(), $now),
                'health' => $this->health($row, $healthScope),
                default => (array) $row,
            };
        }

        return $paginator->setCollection(collect($rows));
    }

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
            default => (array) $row,
        };
    }

    private function query(string $table): Builder
    {
        $this->scope->assertOutsideTransaction();

        return $this->scope->connection->table(PackageSchema::tables()[$table])->where('tenant_key', $this->tenants->resolve()->tenant->value);
    }

    private function projection(string $kind, HealthScope $scope): array
    {
        return match ($kind) {
            'budgets' => ['budgets', ['id', 'scope_key', 'revision', 'emission_interval_us', 'burst', 'max_inflight', 'inflight', 'tat', 'send_tat', 'cooldown_until', 'hour_limit', 'day_limit', 'quota_policy_key', 'traffic_parent_id']],
            'health' => [$scope->value, ['id', ...($scope !== HealthScope::Target ? ['route_id'] : []), ...($scope !== HealthScope::Route ? ['target_id'] : []), 'state', 'state_version', 'health_epoch', 'reason_code', 'open_until', 'probe_until']],
            'attempts' => ['attempts', ['id', 'operation_id', 'physical_attempt_id', 'status', 'authorized_at', 'completed_at']],
            'audit' => ['audit_events', ['id', 'entity_type', 'entity_id', 'event_kind', 'ability', 'previous_revision', 'revision', 'actor_type', 'actor_id', 'reason', 'created_at']],
        };
    }

    private function health(object $row, HealthScope $scope): array
    {
        $data = (array) $row;
        $data['scope'] = $scope->value;
        // reason_code остаётся открытой строкой в storage; UI показывает только код, не произвольный payload.
        $reason = $data['reason_code'];
        $data['reason_code'] = $reason === null ? null : (str_starts_with($reason, 'credential_hold') ? 'credential_hold'
            : (TransportFailure::tryFrom($reason) !== null || preg_match('/^http_[1-5][0-9]{2}$/D', $reason) === 1
                || in_array($reason, ['probe_expired', 'waf_rate_signal'], true) ? $reason : 'health_hold'));

        return $data;
    }

    private function budget(object $row, array $windows, DateTimeImmutable $now): array
    {
        $next = DatabaseClock::micros($now);
        $allowance = (int) $row->emission_interval_us > intdiv(PHP_INT_MAX, max(1, (int) $row->burst - 1))
            ? PHP_INT_MAX : (int) $row->emission_interval_us * ((int) $row->burst - 1);
        foreach (['tat', 'send_tat', 'cooldown_until'] as $field) {
            if ($row->$field !== null && ! in_array($row->$field, ['-infinity', 'infinity'], true)) {
                $next = max($next, DatabaseClock::micros(new DateTimeImmutable($row->$field)) - ($field === 'cooldown_until' ? 0 : $allowance));
            }
        }
        $quotas = [];
        $unknown = false;
        foreach (['hour', 'day'] as $kind) {
            $configured = $row->{$kind.'_limit'};
            if ($configured === null) {
                continue;
            }
            $window = array_find($windows, static fn ($w): bool => $w->window_kind === $kind && $w->policy_key === $row->quota_policy_key);
            $unknown = $unknown || $window === null || (int) $configured === 0;
            if ($window !== null && (int) $window->spent + (int) $window->reserved >= min((int) $window->quota_limit, (int) $configured)) {
                $next = max($next, DatabaseClock::micros(new DateTimeImmutable($window->ends_at)));
            }
            $quotas[] = ['window' => $kind, 'timezone' => 'UTC', 'limit' => (int) $configured,
                'consumed' => $window === null ? null : (int) $window->spent, 'reserved' => $window === null ? null : (int) $window->reserved,
                'starts_at' => $window === null ? null : $this->utc($window->starts_at), 'ends_at' => $window === null ? null : $this->utc($window->ends_at)];
        }
        $dimension = explode(':', $row->scope_key, 2)[0];

        return ['id' => $row->id, 'revision' => (int) $row->revision,
            'scope' => in_array($dimension, ['system', 'tenant', 'panel', 'entity', 'operation', 'origin', 'route', 'route-origin', 'egress-origin', 'traffic', 'probe'], true) ? $dimension : 'custom',
            'scope_fingerprint' => hash('sha256', $row->scope_key), 'quotas' => $quotas,
            'emission_interval_us' => (int) $row->emission_interval_us, 'burst' => (int) $row->burst,
            'inflight' => (int) $row->inflight, 'max_inflight' => (int) $row->max_inflight,
            'hour_limit' => $row->hour_limit === null ? null : (int) $row->hour_limit,
            'day_limit' => $row->day_limit === null ? null : (int) $row->day_limit,
            'editable' => $row->traffic_parent_id === null,
            'next_eligible_at' => $unknown || (int) $row->inflight >= (int) $row->max_inflight || $row->tat === 'infinity' || $row->send_tat === 'infinity' || $row->cooldown_until === 'infinity'
                ? null : DatabaseClock::instant($next)->format('Y-m-d\TH:i:s.u\Z'),
            'cooldown_until' => $row->cooldown_until === null ? null : $this->utc($row->cooldown_until)];
    }

    private function utc(string $date): ?string
    {
        if (in_array($date, ['-infinity', 'infinity'], true)) {
            return null;
        }

        return (new DateTimeImmutable($date))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
