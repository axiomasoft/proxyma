<?php

declare(strict_types=1);

namespace Proxyma\Domain\Health;

enum HealthScope: string
{
    case Route = 'route_health';
    case RouteTarget = 'route_target_health';
    case Target = 'target_health';
}
