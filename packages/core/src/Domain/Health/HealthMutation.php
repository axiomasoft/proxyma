<?php

declare(strict_types=1);

namespace Proxyma\Domain\Health;

use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class HealthMutation
{
    public function __construct(
        public HealthSnapshot $nextSnapshot,
        public bool $transitionedToOpen = false,
        public bool $holdCredential = false,
        public ?DateTimeImmutable $cooldownUntil = null,
    ) {}
}
