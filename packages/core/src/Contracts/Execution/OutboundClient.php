<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Execution;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Domain\Execution\ExecutionContext;
use Proxyma\Domain\Execution\ExecutionResult;
use Proxyma\Domain\Execution\OutboundRequest;

#[Spi(kind: SpiKind::Call, since: '1.0')]
interface OutboundClient
{
    public function execute(OutboundRequest $request, ExecutionContext $context): ExecutionResult;
}
