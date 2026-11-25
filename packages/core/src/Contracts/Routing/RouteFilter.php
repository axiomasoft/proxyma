<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Routing;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Execution\WireRequest;

/**
 * Subset-only route filter extension contract.
 * Implementations may only narrow $profileRoutes; RouteSubsetEnforcer rejects any foreign route.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface RouteFilter
{
    /**
     * @param  list<Route>  $profileRoutes
     * @return list<Route>
     */
    public function filter(array $profileRoutes, WireRequest $request): array;
}
