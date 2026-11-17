<?php

declare(strict_types=1);

namespace Proxyma\Domain\Health;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class HealthClassification
{
    public function __construct(
        public HealthAttribution $attribution,
        public ?string $reasonCode = null,
        public bool $tripImmediately = false,
    ) {
        if ($reasonCode !== null && preg_match('/^[a-z0-9][a-z0-9_.:-]{0,63}$/D', $reasonCode) !== 1) {
            throw new InvalidArgumentException('Health reason code must be a valid canonical identifier.');
        }
    }
}
