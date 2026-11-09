<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use InvalidArgumentException;
use Proxyma\Domain\Catalog\Route;

final readonly class RouteSubsetEnforcer
{
    /**
     * Enforces that a RouteFilter extension only narrows the profile's candidate routes
     * and never introduces foreign or unapproved routes.
     *
     * @param  list<Route>  $profileCandidates
     * @param  list<Route>  $filteredRoutes
     * @return list<Route>
     */
    public static function enforceSubset(array $profileCandidates, array $filteredRoutes): array
    {
        $allowedById = [];
        foreach ($profileCandidates as $candidate) {
            $allowedById[$candidate->id->value] = $candidate;
        }

        $result = [];
        $seen = [];

        foreach ($filteredRoutes as $route) {
            if (! isset($allowedById[$route->id->value])) {
                throw new InvalidArgumentException("RouteFilter cannot introduce route '{$route->id->value}' outside the profile membership.");
            }

            if (! isset($seen[$route->id->value])) {
                $seen[$route->id->value] = true;
                $result[] = $allowedById[$route->id->value];
            }
        }

        return $result;
    }
}
