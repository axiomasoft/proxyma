<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\AuthorizedWireRequest;

interface PhysicalTransport
{
    public function sendOnce(AuthorizedWireRequest $request): AttemptResult;
}
