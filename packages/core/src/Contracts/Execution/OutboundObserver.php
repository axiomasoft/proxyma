<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Execution;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Domain\Execution\ReadOnlyAttemptObservation;

/**
 * Read-only post-attempt observer contract.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface OutboundObserver
{
    public function onAttemptCompleted(ReadOnlyAttemptObservation $observation): void;
}
