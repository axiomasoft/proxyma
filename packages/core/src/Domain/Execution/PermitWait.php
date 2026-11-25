<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class PermitWait
{
    public function __construct(
        public RetryAt $retryAt,
        public DeferralReason $reason,
    ) {}
}
