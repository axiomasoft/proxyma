<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Execution;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Domain\Catalog\Target;
use Proxyma\Domain\Execution\ExecutionContext;
use Proxyma\Domain\Execution\ResolvedUrl;
use Proxyma\Domain\Execution\SafeUrl;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface DestinationResolver
{
    public function resolve(SafeUrl $url, ExecutionContext $context, int $timeoutMs, ?Target $target = null): ResolvedUrl;
}
