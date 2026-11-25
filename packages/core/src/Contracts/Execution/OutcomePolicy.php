<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Execution;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\OutcomeClassification;
use Proxyma\Domain\Execution\WireRequest;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface OutcomePolicy
{
    public function key(): string;

    public function classify(WireRequest $request, AttemptResult $attempt): OutcomeClassification;
}
