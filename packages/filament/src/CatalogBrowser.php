<?php

declare(strict_types=1);

namespace Proxyma\Filament;

use Illuminate\Database\Connection;
use Illuminate\Pagination\Paginator;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Contracts\CatalogQueries;
use Proxyma\Contracts\TenantContextResolver;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Infrastructure\Database\PackageSchema;

class CatalogBrowser
{
    public const CollectionId = '00000000-0000-4000-8000-000000000000';

    public function __construct(private Connection $db, private CatalogAccess $access,
        private CatalogQueries $queries, private TenantContextResolver $tenants) {}

    public function authorize(string $kind): void
    {
        $this->access->authorize($this->tenants->resolve()->tenant, "catalog.{$kind}.list", new Uuid(self::CollectionId));
    }

    public function page(string $kind, int $page = 1, string $search = '', bool $selectable = false): Paginator
    {
        $this->authorize($kind);
        [$table, $column] = match ($kind) {
            'endpoint' => ['endpoints', 'host'],
            'route' => ['routes', 'route_key'],
            'profile' => ['route_profiles', 'profile_key'],
        };
        $tenant = $this->tenants->resolve()->tenant;
        $tables = PackageSchema::tables();
        $query = $this->db->table($tables[$table])->whereNull('retired_at')->where(function ($query) use ($kind, $selectable, $tenant, $tables): void {
            $query->where('tenant_key', $tenant->value);
            if ($kind === 'route' && $selectable) {
                $query->orWhereIn('id', $this->db->table($tables['shared_route_grants'])->select('route_id')
                    ->where('consumer_tenant_key', $tenant->value)->whereNull('retired_at'));
            }
        });
        if ($search !== '') {
            $query->where($column, 'like', '%'.substr($search, 0, 128).'%');
        }
        $paginator = $query->orderBy('id')->simplePaginate(25, ['id'], 'catalogPage', max(1, min(100000, $page)));
        $records = [];
        foreach ($paginator->items() as $row) {
            $id = new Uuid($row->id);
            try {
                $value = match ($kind) {
                    'endpoint' => $this->queries->findEndpoint($tenant, $id),
                    'route' => $this->queries->findRoute($tenant, $id),
                    'profile' => $this->queries->findProfile($tenant, $id),
                };
                if ($selectable) {
                    $this->access->authorize($tenant, "catalog.{$kind}.select", $id);
                }
            } catch (AuthorizationDenied) {
                continue;
            }
            if ($value === null) {
                continue;
            }
            $label = match ($kind) {
                'endpoint' => "{$value->protocol->value}://{$value->host}:{$value->port}",
                'route' => $value->routeKey.($value->kind->value === 'direct' ? ' — прямой' : ''),
                'profile' => $value->profileKey.($value->isEmpty() ? ' — отправка запрещена' : ''),
            };
            $records[] = ['id' => $id->value, 'label' => $label, 'revision' => $value->revision];
        }

        return $paginator->setCollection(collect($records));
    }

    public function options(string $kind, string $search = ''): array
    {
        return collect($this->page($kind, search: $search, selectable: $kind === 'route')->items())->pluck('label', 'id')->all();
    }

    public function optionLabel(string $kind, string $id): ?string
    {
        $tenant = $this->tenants->resolve()->tenant;
        $uuid = new Uuid($id);
        try {
            if ($kind === 'route') {
                $this->access->authorize($tenant, 'catalog.route.select', $uuid);
                $value = $this->queries->findRoute($tenant, $uuid);

                return $value?->routeKey;
            }

            $value = $this->queries->findEndpoint($tenant, $uuid);

            return $value === null ? null : "{$value->host}:{$value->port}";
        } catch (AuthorizationDenied) {
            return null;
        }
    }
}
