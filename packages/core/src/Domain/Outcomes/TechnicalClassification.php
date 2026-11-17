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
 * Результат независимой технической классификации физической попытки.
 * Атрибуция здоровья и инварианты безопасности фиксируются здесь и не могут быть отменены пользовательской политикой.
 */
#[Api(since: '1.0')]
final readonly class TechnicalClassification
{
    public function __construct(
        public AttemptFacts $facts,
        public TechnicalFailureKind $failureKind,
        public HealthAttribution $healthAttribution,
        public OutcomeDecision $defaultDecision,
        public string $businessMeaning,
        public ?DeferralReason $deferralReason = null,
        public ?BlockedReason $blockedReason = null,
        public ?RetryAt $cooldownUntil = null,
        public ?RetryAfterAdvice $retryAfterAdvice = null,
        public bool $isRedirect = false,
        public bool $holdsProxyCredential = false,
        public bool $extendsOriginCooldown = false,
        public bool $appliesWafHold = false,
        public bool $forbidsSameRouteRetry = false,
        public bool $forbidsRouteRotation = false,
        public bool $forbidsAnyRetry = false,
        public bool $isAmbiguousAfterAuthorization = false,
        public bool $isMandatorySecurityBlock = false,
    ) {}

    public function toDefaultOutcomeClassification(): OutcomeClassification
    {
        return new OutcomeClassification(
            decision: $this->defaultDecision,
            businessMeaning: $this->businessMeaning,
            healthAttribution: $this->healthAttribution,
            retryAfter: $this->cooldownUntil,
        );
    }
}
