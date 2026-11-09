<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

final readonly class PermitWait
{
    public function __construct(
        public RetryAt $retryAt,
        public DeferralReason $reason,
    ) {}
}
