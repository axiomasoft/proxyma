<?php

declare(strict_types=1);

namespace Proxyma\Filament;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\Paginator;
use InvalidArgumentException;
use LogicException;
use Packman\Attributes\Api;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Contracts\Authorization\TenantContextResolver;
use Proxyma\Contracts\Catalog\CatalogQueries;
use Proxyma\Domain\Catalog\Endpoint;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Catalog\RouteProfile;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Filament\Internal\FilamentValue;
use Proxyma\Infrastructure\Database\PackageSchema;

#[Api(since: '1.0')]
class CatalogBrowser
{
    public const CollectionId = '00000000-0000-4000-8000-000000000000';

    public function __construct(private Connection $db, private CatalogAccess $access,
        private CatalogQueries $queries, private TenantContextResolver $tenants) {}

    public function authorize(string $kind): void
    {
        $this->access->authorize($this->tenants->resolve()->tenant, "catalog.{$kind}.list", new Uuid(self::CollectionId));
    }

    /** @return Paginator<int, array{id: string, label: string, revision: int}> */
    public function page(string $kind, int $page = 1, string $search = '', bool $selectable = false): Paginator
    {
        $this->authorize($kind);
        [$table, $column] = match ($kind) {
            'endpoint' => ['endpoints', 'host'],
            'route' => ['routes', 'route_key'],
            'profile' => ['route_profiles', 'profile_key'],
            default => throw new InvalidArgumentException('Unknown catalog section.'),
        };
        $tenant = $this->tenants->resolve()->tenant;
        $tables = PackageSchema::tables($this->db);
        $query = $this->db->table($tables[$table])->whereNull('retired_at')->where(function (Builder $query) use ($kind, $selectable, $tenant, $tables): void {
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
        if (! $paginator instanceof Paginator) {
            throw new LogicException('Expected Paginator instance.');
        }
        $records = [];
        foreach ($paginator->items() as $row) {
            if (! is_object($row)) {
                continue;
            }
            $id = new Uuid(FilamentValue::colStr($row, 'id'));
            try {
                $value = match ($kind) {
                    'endpoint' => $this->queries->findEndpoint($tenant, $id),
                    'route' => $this->queries->findRoute($tenant, $id),
                    default => $this->queries->findProfile($tenant, $id),
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
            $label = match (true) {
                $value instanceof Endpoint => "{$value->protocol->value}://{$value->host}:{$value->port}",
                $value instanceof Route => $value->routeKey.($value->kind->value === 'direct' ? ' — прямой' : ''),
                $value instanceof RouteProfile => $value->profileKey.($value->isEmpty() ? ' — отправка запрещена' : ''),
            };
            $records[] = ['id' => $id->value, 'label' => $label, 'revision' => $value->revision];
        }

        /** @var Paginator<int, array{id: string, label: string, revision: int}> $typedPaginator */
        $typedPaginator = $paginator->setCollection(collect($records));

        return $typedPaginator;
    }

    /** @return array<string, string> */
    public function options(string $kind, string $search = ''): array
    {
        $options = [];
        foreach ($this->page($kind, search: $search, selectable: $kind === 'route')->items() as $item) {
            $options[$item['id']] = $item['label'];
        }

        return $options;
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
