<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\EffectivePolicySnapshot;
use Proxyma\Domain\Policy\PolicyExplanation;
use Proxyma\Domain\Policy\SubjectChain;

interface PolicyResolver
{
    public function resolve(SubjectChain $subjects, Uuid $targetId, string $trafficKey = 'default'): EffectivePolicySnapshot;

    public function explain(SubjectChain $subjects, Uuid $targetId, string $trafficKey = 'default'): PolicyExplanation;
}
