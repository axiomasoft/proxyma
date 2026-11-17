<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use Packman\Attributes\Api;
use Proxyma\Domain\Health\HealthEpochMap;
use Proxyma\Domain\Policy\TenantKey;

#[Api(since: '1.0')]
final readonly class CompletionFact
{
    /** @param array<string, mixed> $summary */
    public function __construct(public TenantKey $tenant, public Uuid $attemptId, public Uuid $receiptId, public Uuid $observationId,
        public array $summary, public HealthEpochMap $authorizationEpochs, public HealthEpochMap $liveEpochs) {}
}
