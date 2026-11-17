<?php

declare(strict_types=1);

namespace Proxyma\Domain\Outcomes;

use Packman\Attributes\Api;
use Proxyma\Domain\Execution\BlockedReason;
use Proxyma\Domain\Execution\DeferralReason;
use Proxyma\Domain\Execution\OutcomeClassification;
use Proxyma\Domain\Execution\OutcomeDecision;
use Proxyma\Domain\Execution\RetryAt;
use Proxyma\Domain\Health\HealthAttribution;

/**
 * Итоговый согласованный исход физической попытки:
 * объединяет неизменяемую техническую классификацию (AttemptFacts + HealthAttribution)
 * и проверенное доменное решение OutcomePolicy.
 */
#[Api(since: '1.0')]
final readonly class ResolvedOutcome
{
    public function __construct(
        public AttemptFacts $facts,
        public TechnicalClassification $technical,
        public OutcomeClassification $classification,
        public CallerOutcomeAction $action,
        public OutcomePolicyTier $winningTier,
        public ?DeferralReason $deferralReason = null,
        public ?BlockedReason $blockedReason = null,
    ) {}

    public function decision(): OutcomeDecision
    {
        return $this->classification->decision;
    }

    public function healthAttribution(): HealthAttribution
    {
        return $this->technical->healthAttribution;
    }

    public function retryAfter(): ?RetryAt
    {
        return $this->classification->retryAfter ?? $this->technical->cooldownUntil;
    }

    public function isAmbiguousAfterAuthorization(): bool
    {
        return $this->technical->isAmbiguousAfterAuthorization;
    }

    public function isRedirect(): bool
    {
        return $this->technical->isRedirect;
    }
}
