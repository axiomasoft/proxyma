<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use Illuminate\Database\Connection;
use Packman\Attributes\Internal;
use Proxyma\Domain\Bindings\BindingIdentity;
use Proxyma\Domain\Policy\BudgetConstraint;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Internal\Database\RowValues;

/** @internal Вызывается только из catalog transaction, никогда из send path. */
#[Internal]
final readonly class CatalogProvisioner
{
    public function __construct(private Connection $db) {}

    public function lockBinding(SubjectRef $subject, string $panel, string $slot): void
    {
        $id = BindingIdentity::id($subject, $panel, $slot);
        $this->db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['proxyma:binding:'.$id->value]);
    }

    public function lockCatalog(TenantKey $tenant): void
    {
        // Mutex сериализует создание отсутствующих строк. Runtime его не берёт.
        $this->db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['proxyma:catalog:'.$tenant->value]);
        foreach (['route_profiles', 'profile_health', 'endpoints', 'routes', 'route_health', 'route_target_health', 'targets', 'target_health'] as $table) {
            PackageSchema::query($this->db, $table)->where('tenant_key', $tenant->value)->orderBy('id')->lockForUpdate()->get();
        }
    }

    /** @param list<BudgetConstraint> $budgets */
    public function materialize(TenantKey $tenant, array $budgets): void
    {
        $t = PackageSchema::tables($this->db);
        foreach (['route_profiles' => ['profile_health', 'profile_id'], 'routes' => ['route_health', 'route_id']] as $parent => [$health, $column]) {
            foreach ($this->db->table($t[$parent])->where('tenant_key', $tenant->value)->orderBy('id')->pluck('id') as $id) {
                $this->db->table($t[$health])->insertOrIgnore(['tenant_key' => $tenant->value, $column => $id]);
            }
        }
        $routes = $this->db->table($t['routes'])->where('tenant_key', $tenant->value)->orderBy('id')->pluck('id');
        $targets = $this->db->table($t['targets'])->where('tenant_key', $tenant->value)->orderBy('id')->pluck('id');
        foreach ($routes as $route) {
            foreach ($targets as $target) {
                $this->db->table($t['route_target_health'])->insertOrIgnore(['tenant_key' => $tenant->value, 'route_id' => $route, 'target_id' => $target]);
            }
        }
        foreach ($targets as $target) {
            $this->db->table($t['target_health'])->insertOrIgnore(['tenant_key' => $tenant->value, 'target_id' => $target]);
        }
        $byKey = [];
        foreach ($budgets as $budget) {
            $byKey[$budget->scopeKey] = isset($byKey[$budget->scopeKey]) ? $byKey[$budget->scopeKey]->intersect($budget) : $budget;
        }
        ksort($byKey, SORT_STRING);
        foreach ($byKey as $key => $budget) {
            $query = $this->db->table($t['budgets'])->where('tenant_key', $tenant->value)->where('scope_key', $key);
            $existing = (clone $query)->lockForUpdate()->first();
            $mandatory = $budget->mandatory || ($existing !== null && RowValues::colBool($existing, 'mandatory'));
            $values = ['emission_interval_us' => $budget->emissionIntervalUs, 'burst' => $budget->burst, 'max_inflight' => $budget->maxInflight, 'mandatory' => $mandatory];
            $values['hour_limit'] = $budget->hourLimit ?? ($existing === null ? null : RowValues::colNullableInt($existing, 'hour_limit'));
            $values['day_limit'] = $budget->dayLimit ?? ($existing === null ? null : RowValues::colNullableInt($existing, 'day_limit'));
            if ($existing === null) {
                $this->db->table($t['budgets'])->insert(['tenant_key' => $tenant->value, 'scope_key' => $key] + $values);
            } elseif (RowValues::colInt($existing, 'emission_interval_us') !== $budget->emissionIntervalUs
                || RowValues::colInt($existing, 'burst') !== $budget->burst
                || RowValues::colInt($existing, 'max_inflight') !== $budget->maxInflight
                || RowValues::colBool($existing, 'mandatory') !== $mandatory
                || RowValues::colNullableInt($existing, 'hour_limit') !== $values['hour_limit']
                || RowValues::colNullableInt($existing, 'day_limit') !== $values['day_limit']) {
                $query->update($values + ['revision' => RowValues::colInt($existing, 'revision') + 1, 'updated_at' => $this->db->raw('clock_timestamp()')]);
            }
            $materialized = RowValues::object((clone $query)->first());
            QuotaRepository::provisionWindows($this->db, $materialized, DatabaseClock::now($this->db), reconcileHistory: true);
        }
    }
}
