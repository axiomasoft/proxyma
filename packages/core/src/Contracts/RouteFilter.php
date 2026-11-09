<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Execution\WireRequest;

/**
 * Subset-only route filter extension contract.
 * Implementations may only narrow $profileRoutes; RouteSubsetEnforcer rejects any foreign route.
 */
interface RouteFilter
{
    /**
     * @param  list<Route>  $profileRoutes
     * @return list<Route>
     */
    public function filter(array $profileRoutes, WireRequest $request): array;
}
