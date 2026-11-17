<?php

declare(strict_types=1);

namespace Proxyma\Domain\Health;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum HealthScope: string
{
    case Route = 'route_health';
    case RouteTarget = 'route_target_health';
    case Target = 'target_health';
}
