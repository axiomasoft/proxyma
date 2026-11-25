<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum DeferralReason: string
{
    case RateLimit = 'rate_limit';
    case CapacityWait = 'capacity_wait';
    case QuotaWindowExhausted = 'quota_window_exhausted';
    case TargetCooldown = 'target_cooldown';
    case RouteCooldown = 'route_cooldown';
    case SliceExhausted = 'slice_exhausted';
    case DependencyUnavailable = 'dependency_unavailable';
    case AttemptsExhausted = 'attempts_exhausted';
    case NoHealthyRoute = 'no_healthy_route';
}
