<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Execution;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\AuthorizedWireRequest;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface PhysicalTransport
{
    public function sendOnce(AuthorizedWireRequest $request): AttemptResult;
}
