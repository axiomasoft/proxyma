<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Proxyma\Domain\Catalog\Target;
use Proxyma\Domain\Execution\ExecutionContext;
use Proxyma\Domain\Execution\ResolvedUrl;
use Proxyma\Domain\Execution\SafeUrl;

interface DestinationResolver
{
    public function resolve(SafeUrl $url, ExecutionContext $context, int $timeoutMs, ?Target $target = null): ResolvedUrl;
}
