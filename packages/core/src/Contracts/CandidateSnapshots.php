<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\EffectivePolicy;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Domain\Routing\CandidateSnapshot;

interface CandidateSnapshots
{
    public function load(TenantKey $tenant, Uuid $profileId, Uuid $targetId, EffectivePolicy $policy): CandidateSnapshot;
}
