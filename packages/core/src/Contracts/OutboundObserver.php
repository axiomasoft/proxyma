<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Proxyma\Domain\Execution\ReadOnlyAttemptObservation;

/**
 * Read-only post-attempt observer contract.
 */
interface OutboundObserver
{
    public function onAttemptCompleted(ReadOnlyAttemptObservation $observation): void;
}
