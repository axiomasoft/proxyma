<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use Illuminate\Database\Connection;
use Proxyma\Domain\Policy\BudgetConstraint;
use Proxyma\Domain\Policy\TenantKey;

/** @internal Вызывается только из catalog transaction, никогда из send path. */
final readonly class CatalogProvisioner
{
    public function __construct(private Connection $db) {}

    public function lockCatalog(TenantKey $tenant): void
    {
        // Mutex сериализует создание отсутствующих строк. Runtime его не берёт.
        $this->db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['proxyma:catalog:'.$tenant->value]);
        foreach (['route_profiles', 'profile_health', 'endpoints', 'routes', 'route_health', 'route_target_health', 'targets', 'target_health'] as $table) {
            $this->db->table(PackageSchema::tables()[$table])->where('tenant_key', $tenant->value)->orderBy('id')->lockForUpdate()->get();
        }
    }

    /** @param list<BudgetConstraint> $budgets */
    public function materialize(TenantKey $tenant, array $budgets): void
    {
        $t = PackageSchema::tables();
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
            $mandatory = $budget->mandatory || (bool) ($existing->mandatory ?? false);
            $values = ['emission_interval_us' => $budget->emissionIntervalUs, 'burst' => $budget->burst, 'max_inflight' => $budget->maxInflight, 'mandatory' => $mandatory];
            if ($existing === null) {
                $this->db->table($t['budgets'])->insert(['tenant_key' => $tenant->value, 'scope_key' => $key] + $values);
            } elseif ((int) $existing->emission_interval_us !== $budget->emissionIntervalUs || (int) $existing->burst !== $budget->burst || (int) $existing->max_inflight !== $budget->maxInflight || (bool) $existing->mandatory !== $mandatory) {
                $query->update($values + ['revision' => $existing->revision + 1, 'updated_at' => $this->db->raw('clock_timestamp()')]);
            }
        }
    }
}
