<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use Proxyma\Domain\Health\HealthEpochMap;
use Proxyma\Domain\Policy\TenantKey;

final readonly class CompletionFact
{
    public function __construct(public TenantKey $tenant, public Uuid $attemptId, public Uuid $receiptId, public Uuid $observationId,
        public array $summary, public HealthEpochMap $authorizationEpochs, public HealthEpochMap $liveEpochs) {}
}
