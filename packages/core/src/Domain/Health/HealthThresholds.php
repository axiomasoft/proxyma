<?php

declare(strict_types=1);

namespace Proxyma\Domain\Health;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class HealthThresholds
{
    public function __construct(
        public int $globalConsecutive = 5,
        public int $globalBaseSeconds = 1800,
        public int $globalCapSeconds = 21600,
        public int $routeTargetConsecutive = 5,
        public int $routeTargetMinSample = 10,
        public int $routeTargetFailurePercent = 60,
        public int $routeTargetWindowSeconds = 600,
        public int $routeTargetBaseSeconds = 300,
        public int $routeTargetCapSeconds = 3600,
        public int $stableDecaySeconds = 600,
        public int $corroborationWindowSeconds = 300,
        public int $originCooldownSeconds = 900,
    ) {
        foreach (get_object_vars($this) as $value) {
            if ($value < 1) {
                throw new InvalidArgumentException('Health thresholds must be positive integers.');
            }
        }
        if ($routeTargetFailurePercent > 100 || $globalCapSeconds < $globalBaseSeconds || $routeTargetCapSeconds < $routeTargetBaseSeconds) {
            throw new InvalidArgumentException('Invalid health percentage or backoff cap.');
        }
    }
}
