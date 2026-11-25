<?php

declare(strict_types=1);

namespace Proxyma\Domain\Admission;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;
use Proxyma\Errors\ProxymaErrorCode;

/** Must escape the owning transaction so earlier finance changes roll back. */
#[Api(since: '1.0')]
final class ReservationDeferred extends CodedException
{
    public function __construct(public readonly DemandWait $wait)
    {
        parent::__construct('Reservation deferred: '.$wait->reason);
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: ProxymaErrorCode::ReservationDeferred,
            category: ErrorCategory::RateLimit,
            retryable: true,
            details: ['reason' => $this->wait->reason],
        );
    }
}
