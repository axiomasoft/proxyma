<?php

declare(strict_types=1);

namespace Proxyma\Domain\Health;

enum HealthAttribution: string
{
    case None = 'none';
    case RouteSuccess = 'route_success';
    case RouteFailure = 'route_failure';
    case RouteTargetFailure = 'route_target_failure';
    case TargetFailure = 'target_failure';
    case CredentialFailure = 'credential_failure';
}
