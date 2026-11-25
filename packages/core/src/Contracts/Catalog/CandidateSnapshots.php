<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Catalog;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\EffectivePolicy;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Domain\Routing\CandidateSnapshot;

#[Spi(kind: SpiKind::Call, since: '1.0')]
interface CandidateSnapshots
{
    public function load(TenantKey $tenant, Uuid $profileId, Uuid $targetId, EffectivePolicy $policy): CandidateSnapshot;
}
