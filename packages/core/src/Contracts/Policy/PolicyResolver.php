<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Policy;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\EffectivePolicySnapshot;
use Proxyma\Domain\Policy\PolicyExplanation;
use Proxyma\Domain\Policy\SubjectChain;

#[Spi(kind: SpiKind::Call, since: '1.0')]
interface PolicyResolver
{
    public function resolve(SubjectChain $subjects, Uuid $targetId, string $trafficKey = 'default'): EffectivePolicySnapshot;

    public function explain(SubjectChain $subjects, Uuid $targetId, string $trafficKey = 'default'): PolicyExplanation;
}
