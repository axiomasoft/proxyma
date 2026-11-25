<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Packman\Attributes\Api;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Catalog\CatalogInvalidMutation;
use Proxyma\Application\Catalog\CatalogStorageFailure;
use Proxyma\Contracts\Catalog\CatalogQueries;
use Proxyma\Contracts\Catalog\RouteAccessChecks;
use Proxyma\Domain\Catalog\Endpoint;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Catalog\RouteProfile;
use Proxyma\Domain\Catalog\Target;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Audit\CatalogAudit;
use Proxyma\Infrastructure\Security\SharedRouteStorage;
use Proxyma\Internal\Database\RowValues;

/** @internal */
#[Api(since: '1.0')]
final readonly class DatabaseCatalogQueries implements CatalogQueries, RouteAccessChecks
{
    public function __construct(private Connection $db, private CatalogAccess $access) {}

    public function findEndpoint(TenantKey $tenant, Uuid $endpointId): ?Endpoint
    {
        return $this->read('endpoints', 'catalog.endpoint.read', $tenant, $endpointId, function () use ($tenant, $endpointId): ?Endpoint {
            $row = $this->row('endpoints', $tenant, $endpointId);

            return $row === null ? null : CatalogRowMapper::endpoint($row);
        });
    }

    public function findRoute(TenantKey $tenant, Uuid $routeId): ?Route
    {
        return $this->read('routes', 'catalog.route.read', $tenant, $routeId, fn (): ?Route => $this->accessibleRoute($tenant, $routeId));
    }

    public function findProfile(TenantKey $tenant, Uuid $profileId): ?RouteProfile
    {
        return $this->read('route_profiles', 'catalog.profile.read', $tenant, $profileId, function () use ($tenant, $profileId): ?RouteProfile {
            $row = $this->row('route_profiles', $tenant, $profileId);
            if ($row === null) {
                return null;
            }

            return CatalogRowMapper::profile($row, $this->members($tenant, $profileId));
        });
    }

    public function findTarget(TenantKey $tenant, Uuid $targetId): ?Target
    {
        return $this->read('targets', 'catalog.target.read', $tenant, $targetId, function () use ($tenant, $targetId): ?Target {
            $row = $this->row('targets', $tenant, $targetId);

            return $row === null ? null : CatalogRowMapper::target($row);
        });
    }

    public function routesForProfile(TenantKey $tenant, Uuid $profileId): array
    {
        $context = $this->access->authorize($tenant, 'catalog.profile.select', $profileId);
        try {
            $profile = $this->row('route_profiles', $tenant, $profileId);
            $routes = [];
            if ($profile !== null && RowValues::col($profile, 'retired_at') === null) {
                $members = PackageSchema::query($this->db, 'route_profile_members')
                    ->where('tenant_key', $tenant->value)->where('profile_id', $profileId->value)->orderBy('position')->get();
                foreach ($members as $member) {
                    $id = new Uuid(RowValues::colStr($member, 'route_id'));
                    $this->access->authorize($tenant, 'catalog.route.select', $id);
                    $route = $this->accessibleRoute($tenant, $id);
                    if ($route === null || $route->tenantKey->value !== RowValues::colStr($member, 'route_owner_tenant_key')
                        || $route->accessGrant?->id->value !== RowValues::colNullableStr($member, 'shared_grant_id')) {
                        throw new AuthorizationDenied;
                    }
                    $routes[] = $route;
                }
            }
            new CatalogAudit($this->db)->read('route_profiles', $profileId, 'catalog.profile.select', $context, $profile === null ? 0 : RowValues::colInt($profile, 'revision'));

            return $routes;
        } catch (QueryException) {
            throw new CatalogStorageFailure;
        }
    }

    public function assertCurrent(Route $prepared): void
    {
        try {
            $this->validatePrepared($prepared);
        } catch (QueryException) {
            throw new CatalogStorageFailure;
        }
    }

    private function validatePrepared(Route $prepared): void
    {
        if ($this->db->transactionLevel() === 0) {
            throw new CatalogInvalidMutation('Prepared route validation requires the owning transaction.');
        }
        $consumer = $prepared->accessGrant->consumer ?? $prepared->tenantKey;
        $this->access->authorize($consumer, 'catalog.route.select', $prepared->id);
        $t = PackageSchema::tables($this->db);
        if ($prepared->endpointId !== null) {
            $endpoint = $this->db->table($t['endpoints'])->where('tenant_key', $prepared->tenantKey->value)
                ->where('id', $prepared->endpointId->value)->sharedLock()->first();
            if ($endpoint === null || RowValues::col($endpoint, 'retired_at') !== null || RowValues::colInt($endpoint, 'revision') !== $prepared->endpointRevision) {
                throw new AuthorizationDenied;
            }
        }
        $this->db->table($t['routes'])->where('tenant_key', $prepared->tenantKey->value)->where('id', $prepared->id->value)->sharedLock()->first();
        if ($prepared->accessGrant !== null) {
            $this->db->table($t['shared_route_grants'])->where('tenant_key', $prepared->tenantKey->value)
                ->where('consumer_tenant_key', $consumer->value)->where('id', $prepared->accessGrant->id->value)->sharedLock()->first();
        }
        $current = $this->findRoute($consumer, $prepared->id);
        if ($current === null || ! $current->enabled || $current->isRetired()
            || $current->revision !== $prepared->revision || $current->credentialVersion !== $prepared->credentialVersion
            || $current->accessGrant?->id->value !== $prepared->accessGrant?->id->value
            || $current->accessGrant?->revision !== $prepared->accessGrant?->revision) {
            throw new AuthorizationDenied;
        }
    }

    private function accessibleRoute(TenantKey $tenant, Uuid $id): ?Route
    {
        $grant = null;
        $row = $this->row('routes', $tenant, $id);
        if ($row === null) {
            $grant = new SharedRouteStorage($this->db)->forConsumer($tenant, $id);
            $row = $grant === null ? null : $this->row('routes', $grant->owner, $id);
        }
        if ($row === null || ($grant !== null && RowValues::col($row, 'retired_at') !== null)) {
            return null;
        }
        $endpointId = RowValues::colNullableStr($row, 'endpoint_id');
        $endpoint = $endpointId === null ? null : $this->row('endpoints', new TenantKey(RowValues::colStr($row, 'tenant_key')), new Uuid($endpointId));
        if ($endpoint !== null && RowValues::col($endpoint, 'retired_at') !== null && $grant !== null) {
            return null;
        }

        return CatalogRowMapper::route(
            $row,
            $grant,
            $endpoint === null ? 1 : (RowValues::colNullableInt($endpoint, 'credential_version') ?? 1),
            $endpoint === null ? null : RowValues::colInt($endpoint, 'revision'),
        );
    }

    /** @return list<string> */
    private function members(TenantKey $tenant, Uuid $profileId): array
    {
        return RowValues::strList(PackageSchema::query($this->db, 'route_profile_members')
            ->where('tenant_key', $tenant->value)->where('profile_id', $profileId->value)->orderBy('position')->pluck('route_id')->all());
    }

    /**
     * @template T of Endpoint|Route|RouteProfile|Target
     *
     * @param  Closure(): ?T  $load
     * @return ?T
     */
    private function read(string $table, string $ability, TenantKey $tenant, Uuid $id, Closure $load): mixed
    {
        $context = $this->access->authorize($tenant, $ability, $id);
        try {
            $result = $load();
            new CatalogAudit($this->db)->read($table, $id, $ability, $context, $result->revision ?? 0);

            return $result;
        } catch (QueryException) {
            throw new CatalogStorageFailure;
        }
    }

    private function row(string $table, TenantKey $tenant, Uuid $id): ?object
    {
        return PackageSchema::query($this->db, $table)->where('tenant_key', $tenant->value)->where('id', $id->value)->first();
    }
}
