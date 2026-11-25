<?php

declare(strict_types=1);

namespace Proxyma\Domain\Admission;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;
use Proxyma\Errors\ProxymaErrorCode;

/** Thrown when a joint admission transaction rejects the request. */
#[Api(since: '1.0')]
final class AdmissionRejected extends CodedException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Joint admission rejected: {$reason}");
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: ProxymaErrorCode::AdmissionRejected,
            category: ErrorCategory::Conflict,
            details: ['reason' => $this->reason],
        );
    }
}
