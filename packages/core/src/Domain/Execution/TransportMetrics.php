<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;

final readonly class TransportMetrics
{
    public function __construct(
        public int $dnsLookupMs = 0,
        public int $connectMs = 0,
        public int $tlsHandshakeMs = 0,
        public int $firstByteMs = 0,
        public int $totalMs = 0,
    ) {
        if (min($dnsLookupMs, $connectMs, $tlsHandshakeMs, $firstByteMs, $totalMs) < 0) {
            throw new InvalidArgumentException('Transport timing metrics must be non-negative.');
        }
    }
}
