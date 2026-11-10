<?php

declare(strict_types=1);

namespace Proxyma\Domain\Admission;

use Proxyma\Domain\Execution\RouteSelection;
use Proxyma\Domain\Execution\WireRequest;

final class DemandIntent
{
    public static function hash(WireRequest $request, RouteSelection $route, array $budgets): string
    {
        $keys = array_map(static fn ($b) => $b->tenant_key.':'.$b->id, $budgets);
        sort($keys, SORT_STRING);

        return hash('sha256', json_encode([$request->fingerprint()->hex, $request->deadlineAt->format('U.u'), $route->routeId->value,
            $route->profileId?->value, $route->endpointId?->value, $route->credentialVersion, $route->egress->key, $keys], JSON_THROW_ON_ERROR));
    }
}
