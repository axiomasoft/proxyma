<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;

final readonly class RequestTimeoutPolicy
{
    /** @var list<int> */
    public array $retryDelaysMs;

    /**
     * @param  list<int>  $retryDelaysMs
     */
    public function __construct(
        public int $connectTimeoutMs,
        public int $readTimeoutMs,
        public int $totalTimeoutMs,
        public int $cleanupMarginMs = 15_000,
        public int $maxAttempts = 1,
        public int $maxDistinctRoutes = 1,
        public int $maxRedirects = 0,
        array $retryDelaysMs = [],
    ) {
        if ($connectTimeoutMs < 1 || $readTimeoutMs < 1 || $totalTimeoutMs < 1) {
            throw new InvalidArgumentException('Connect, read, and total timeouts must be positive milliseconds.');
        }

        if ($totalTimeoutMs < $connectTimeoutMs || $totalTimeoutMs < $readTimeoutMs) {
            throw new InvalidArgumentException('Total timeout must be at least as large as connect and read timeouts.');
        }

        if ($cleanupMarginMs < 0) {
            throw new InvalidArgumentException('Cleanup margin must not be negative.');
        }

        if ($maxAttempts < 1 || $maxAttempts > 10) {
            throw new InvalidArgumentException('maxAttempts must be between 1 and 10.');
        }

        if ($maxDistinctRoutes < 1 || $maxDistinctRoutes > $maxAttempts) {
            throw new InvalidArgumentException('maxDistinctRoutes must be between 1 and maxAttempts.');
        }

        if ($maxRedirects < 0 || $maxRedirects > 10) {
            throw new InvalidArgumentException('maxRedirects must be between 0 and 10.');
        }

        $delays = [];
        foreach ($retryDelaysMs as $delay) {
            if ($delay < 0) {
                throw new InvalidArgumentException('Retry delays must be non-negative integer milliseconds.');
            }
            $delays[] = $delay;
        }

        $this->retryDelaysMs = $delays;
    }

    public function totalDuration(): Duration
    {
        return new Duration($this->totalTimeoutMs);
    }

    public function totalWithCleanupMarginNs(): int
    {
        return ($this->totalTimeoutMs + $this->cleanupMarginMs) * 1_000_000;
    }
}
