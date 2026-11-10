<?php

declare(strict_types=1);

namespace Proxyma\Domain\Admission;

use Proxyma\Domain\Execution\RetryAt;

/** Queue scheduling only; this result is never a permit. */
final readonly class DemandWait
{
    public function __construct(public string $reason, public RetryAt $retryAt) {}
}
