<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use Illuminate\Database\Connection;
use Proxyma\Contracts\CatalogQueries;
use Proxyma\Domain\Catalog\Endpoint;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Catalog\RouteProfile;
use Proxyma\Domain\Catalog\Target;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;

/** @internal */
final readonly class DatabaseCatalogQueries implements CatalogQueries
{
    public function __construct(private Connection $db) {}

    public function findEndpoint(TenantKey $tenant, Uuid $endpointId): ?Endpoint
    {
        $row = $this->row('endpoints', $tenant, $endpointId);

        return $row === null ? null : CatalogRowMapper::endpoint($row);
    }

    public function findRoute(TenantKey $tenant, Uuid $routeId): ?Route
    {
        $row = $this->row('routes', $tenant, $routeId);
        if ($row === null) {
            return null;
        }
        $row->credential_version = $row->endpoint_id === null ? 1 : $this->row('endpoints', $tenant, new Uuid($row->endpoint_id))?->credential_version;

        return CatalogRowMapper::route($row);
    }

    public function findProfile(TenantKey $tenant, Uuid $profileId): ?RouteProfile
    {
        $row = $this->row('route_profiles', $tenant, $profileId);
        if ($row === null) {
            return null;
        }
        $row->member_route_ids = $this->db->table(PackageSchema::tables()['route_profile_members'])
            ->where('tenant_key', $tenant->value)->where('profile_id', $profileId->value)->orderBy('position')->pluck('route_id')->all();

        return CatalogRowMapper::profile($row);
    }

    public function findTarget(TenantKey $tenant, Uuid $targetId): ?Target
    {
        $row = $this->row('targets', $tenant, $targetId);

        return $row === null ? null : CatalogRowMapper::target($row);
    }

    public function routesForProfile(TenantKey $tenant, Uuid $profileId): array
    {
        $t = PackageSchema::tables();

        return $this->db->table($t['route_profile_members'].' as m')
            ->join($t['routes'].' as r', static fn ($join) => $join->on('r.id', '=', 'm.route_id')->on('r.tenant_key', '=', 'm.tenant_key'))
            ->leftJoin($t['endpoints'].' as e', static fn ($join) => $join->on('e.id', '=', 'r.endpoint_id')->on('e.tenant_key', '=', 'r.tenant_key'))
            ->where('m.tenant_key', $tenant->value)->where('m.profile_id', $profileId->value)->orderBy('m.position')
            ->select('r.*', 'e.credential_version')->get()->map(static fn (object $row): Route => CatalogRowMapper::route($row))->all();
    }

    private function row(string $table, TenantKey $tenant, Uuid $id): ?object
    {
        return $this->db->table(PackageSchema::tables()[$table])->where('tenant_key', $tenant->value)->where('id', $id->value)->first();
    }
}
