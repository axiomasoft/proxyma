<?php

declare(strict_types=1);

namespace Proxyma\Domain\Admission;

use Packman\Attributes\Api;
use Proxyma\Domain\Execution\RetryAt;

/** Queue scheduling only; this result is never a permit. */
#[Api(since: '1.0')]
final readonly class DemandWait
{
    public function __construct(public string $reason, public RetryAt $retryAt) {}
}
