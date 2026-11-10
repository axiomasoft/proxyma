<?php

declare(strict_types=1);

namespace Consumer;

use Proxyma\Contracts\OutcomePolicy;
use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\OutcomeClassification;
use Proxyma\Domain\Execution\OutcomeDecision;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Health\HealthAttribution;

final class AcceptResponse implements OutcomePolicy
{
    public function key(): string
    {
        return 'consumer.accept';
    }

    public function classify(WireRequest $request, AttemptResult $attempt): OutcomeClassification
    {
        return new OutcomeClassification(OutcomeDecision::Accept, 'consumer_response', HealthAttribution::None);
    }
}
