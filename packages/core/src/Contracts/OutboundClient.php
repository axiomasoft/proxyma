<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Proxyma\Domain\Execution\ExecutionContext;
use Proxyma\Domain\Execution\ExecutionResult;
use Proxyma\Domain\Execution\OutboundRequest;

interface OutboundClient
{
    public function execute(OutboundRequest $request, ExecutionContext $context): ExecutionResult;
}
