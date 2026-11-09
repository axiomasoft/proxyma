<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\OutcomeClassification;
use Proxyma\Domain\Execution\WireRequest;

interface OutcomePolicy
{
    public function key(): string;

    public function classify(WireRequest $request, AttemptResult $attempt): OutcomeClassification;
}
