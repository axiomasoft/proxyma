<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Proxyma\Domain\Catalog\Endpoint;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Catalog\RouteProfile;
use Proxyma\Domain\Catalog\Target;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;

interface CatalogQueries
{
    public function findEndpoint(TenantKey $tenant, Uuid $endpointId): ?Endpoint;

    public function findRoute(TenantKey $tenant, Uuid $routeId): ?Route;

    public function findProfile(TenantKey $tenant, Uuid $profileId): ?RouteProfile;

    public function findTarget(TenantKey $tenant, Uuid $targetId): ?Target;

    /**
     * @return list<Route>
     */
    public function routesForProfile(TenantKey $tenant, Uuid $profileId): array;
}
