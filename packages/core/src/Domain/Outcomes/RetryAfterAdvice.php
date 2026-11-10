<?php

declare(strict_types=1);

namespace Proxyma\Domain\Outcomes;

use DateTimeImmutable;
use Proxyma\Domain\Execution\RetryAt;

final readonly class RetryAfterAdvice
{
    public function __construct(
        public RetryAt $retryAt,
        public int $delaySeconds,
        public bool $fromHeader,
        public bool $isExcessive = false,
        public bool $isInvalid = false,
        public ?int $rawRequestedSeconds = null,
    ) {}

    public function instant(): DateTimeImmutable
    {
        return $this->retryAt->value;
    }
}
