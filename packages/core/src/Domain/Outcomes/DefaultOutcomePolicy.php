<?php

declare(strict_types=1);

namespace Proxyma\Domain\Outcomes;

use DateTimeImmutable;
use Packman\Attributes\Api;
use Proxyma\Contracts\Execution\OutcomePolicy;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\OutcomeClassification;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;

/**
 * Базовая политика исхода по умолчанию (уровень Default).
 */
#[Api(since: '1.0')]
final readonly class DefaultOutcomePolicy implements OutcomePolicy
{
    public function __construct(
        private TechnicalOutcomeClassifier $classifier = new TechnicalOutcomeClassifier,
        private string $policyKey = 'default',
    ) {}

    public function key(): string
    {
        return $this->policyKey;
    }

    public function classify(WireRequest $request, AttemptResult $attempt): OutcomeClassification
    {
        $facts = AttemptFacts::fromAttempt(
            request: $request,
            result: $attempt,
            routeId: new Uuid('00000000-0000-4000-8000-000000000001'),
            routeKind: RouteKind::Proxy,
            endpointId: null,
            egressKey: 'egress:default',
            credentialVersion: 1,
        );

        return $this->classifier
            ->classify($facts, new DateTimeImmutable('now'))
            ->toDefaultOutcomeClassification();
    }
}
