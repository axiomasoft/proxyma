<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

enum OutcomeDecision: string
{
    case Accept = 'accept';
    case RetrySameRoute = 'retry_same_route';
    case RetryDifferentRoute = 'retry_different_route';
    case Defer = 'defer';
    case Block = 'block';
}
