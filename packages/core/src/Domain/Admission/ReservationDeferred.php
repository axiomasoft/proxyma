<?php

declare(strict_types=1);

namespace Proxyma\Domain\Admission;

use RuntimeException;

/** Must escape the owning transaction so earlier finance changes roll back. */
final class ReservationDeferred extends RuntimeException
{
    public function __construct(public readonly DemandWait $wait)
    {
        parent::__construct('Reservation deferred: '.$wait->reason);
    }
}
