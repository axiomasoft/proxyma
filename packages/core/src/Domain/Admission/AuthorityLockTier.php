<?php

declare(strict_types=1);

namespace Proxyma\Domain\Admission;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum AuthorityLockTier: int
{
    case Owner = 10;
    case Finance = 20;
    case Operation = 30;
    case Policy = 40;
    case Profile = 50;
    case Endpoint = 60;
    case Route = 70;
    case RouteHealth = 80;
    case RouteTargetHealth = 90;
    case Target = 100;
    case TargetHealth = 110;
    case AdapterGuard = 120;
    case Budget = 130;
    case Demand = 140;
    case Permit = 150;
    case Attempt = 160;
    case Observation = 170;
    case Application = 180;
}
