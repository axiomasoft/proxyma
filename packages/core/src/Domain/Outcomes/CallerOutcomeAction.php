<?php

declare(strict_types=1);

namespace Proxyma\Domain\Outcomes;

use Proxyma\Domain\Execution\OutcomeDecision;

/**
 * Допустимые возвраты пользовательской политики исхода (OutcomePolicy).
 */
enum CallerOutcomeAction: string
{
    case ReturnResponse = 'return_response';
    case RetrySameRoute = 'retry_same_route';
    case RetryAnotherRoute = 'retry_another_route';
    case DeferUntil = 'defer_until';
    case Block = 'block';
    case RequireReview = 'require_review';

    public function toOutcomeDecision(): OutcomeDecision
    {
        return match ($this) {
            self::ReturnResponse => OutcomeDecision::Accept,
            self::RetrySameRoute => OutcomeDecision::RetrySameRoute,
            self::RetryAnotherRoute => OutcomeDecision::RetryDifferentRoute,
            self::DeferUntil => OutcomeDecision::Defer,
            self::Block, self::RequireReview => OutcomeDecision::Block,
        };
    }

    public static function fromOutcomeDecision(OutcomeDecision $decision, string $businessMeaning = ''): self
    {
        return match ($decision) {
            OutcomeDecision::Accept => self::ReturnResponse,
            OutcomeDecision::RetrySameRoute => self::RetrySameRoute,
            OutcomeDecision::RetryDifferentRoute => self::RetryAnotherRoute,
            OutcomeDecision::Defer => self::DeferUntil,
            OutcomeDecision::Block => str_contains($businessMeaning, 'review')
                ? self::RequireReview
                : self::Block,
        };
    }
}
