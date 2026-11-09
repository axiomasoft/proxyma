<?php

declare(strict_types=1);

namespace Proxyma\Domain\Health;

use DateTimeImmutable;
use InvalidArgumentException;
use Proxyma\Domain\Execution\Uuid;

final readonly class HealthSnapshot
{
    public function __construct(
        public Uuid $id,
        public CircuitState $state,
        public int $healthEpoch,
        public int $stateVersion,
        public int $consecutiveFailures = 0,
        public int $recentOpenings = 0,
        public ?string $reasonCode = null,
        public ?DateTimeImmutable $lastSuccessAt = null,
        public ?DateTimeImmutable $lastFailureAt = null,
        public ?DateTimeImmutable $openUntil = null,
        public ?Uuid $probeToken = null,
        public ?DateTimeImmutable $probeUntil = null,
        public ?DateTimeImmutable $stableSince = null,
        public ?TargetAvailability $availability = null,
    ) {
        if ($healthEpoch < 1 || $stateVersion < 1) {
            throw new InvalidArgumentException('Health epoch and state version must be at least 1.');
        }

        if ($consecutiveFailures < 0 || $recentOpenings < 0) {
            throw new InvalidArgumentException('Health failure and opening counters must be non-negative.');
        }

        if (($probeToken === null) !== ($probeUntil === null)) {
            throw new InvalidArgumentException('Probe token and probe deadline must be set or cleared together.');
        }
    }
}
