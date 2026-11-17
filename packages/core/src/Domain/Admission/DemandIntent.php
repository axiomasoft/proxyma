<?php

declare(strict_types=1);

namespace Proxyma\Domain\Admission;

use Packman\Attributes\Api;
use Proxyma\Domain\Execution\RouteSelection;
use Proxyma\Domain\Execution\WireRequest;

#[Api(since: '1.0')]
final class DemandIntent
{
    /** @param list<object> $budgets */
    public static function hash(WireRequest $request, RouteSelection $route, array $budgets): string
    {
        $keys = array_map(static function (object $b): string {
            $vars = get_object_vars($b);
            $tenantKey = $vars['tenant_key'] ?? '';
            $id = $vars['id'] ?? '';

            return (is_string($tenantKey) ? $tenantKey : '').':'.(is_string($id) ? $id : '');
        }, $budgets);
        sort($keys, SORT_STRING);

        return hash('sha256', json_encode([$request->fingerprint()->hex, $request->deadlineAt->format('U.u'), $route->routeId->value,
            $route->profileId?->value, $route->endpointId?->value, $route->credentialVersion, $route->egress->key, $keys], JSON_THROW_ON_ERROR));
    }
}
