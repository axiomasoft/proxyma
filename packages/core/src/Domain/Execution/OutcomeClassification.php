<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Proxyma\Domain\Health\HealthAttribution;

/**
 * Separates business meaning and health attribution from the physical transport delivery fact.
 * An HTTP 404 or 503 response can be physically delivered while OutcomeClassification records
 * its domain meaning without altering the underlying ResponseDescriptor.
 */
#[Api(since: '1.0')]
final readonly class OutcomeClassification
{
    public string $businessMeaning;

    public function __construct(
        public OutcomeDecision $decision,
        string $businessMeaning,
        public HealthAttribution $healthAttribution = HealthAttribution::None,
        public ?RetryAt $retryAfter = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $businessMeaning) !== 1) {
            throw new InvalidArgumentException('Outcome businessMeaning must be a valid canonical identifier.');
        }

        if ($decision === OutcomeDecision::Defer && $retryAfter === null) {
            throw new InvalidArgumentException('Deferred outcome classification requires a RetryAt timestamp.');
        }

        $this->businessMeaning = $businessMeaning;
    }
}
