<?php

declare(strict_types=1);

namespace Proxyma\Domain\Admission;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class QueueLimits
{
    public function __construct(public int $maxPendingPerScope = 64, public int $ticketTtlMs = 30000,
        public int $maxEligibleSkip = 8, public int $retryMs = 100, public int $maxOperationMs = 3600000)
    {
        if ($maxPendingPerScope < 1 || $maxPendingPerScope > 64 || $ticketTtlMs < 1 || $ticketTtlMs > 60000
            || $maxEligibleSkip < 0 || $maxEligibleSkip > 64 || $retryMs < 1 || $retryMs > 1000
            || $maxOperationMs < 1 || $maxOperationMs > 3600000) {
            throw new InvalidArgumentException('Queue limits exceed bounded hard limits.');
        }
    }
}
