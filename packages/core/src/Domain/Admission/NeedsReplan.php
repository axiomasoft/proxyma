<?php

declare(strict_types=1);

namespace Proxyma\Domain\Admission;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Errors\ProxymaErrorCode;

/** Thrown when the quota window ended prior to sending and the physical attempt must be replanned. */
#[Api(since: '1.0')]
final class NeedsReplan extends CodedException
{
    public bool $released = false;

    public function __construct(public readonly TenantKey $tenant, public readonly Uuid $operationId, public readonly Uuid $permitId)
    {
        parent::__construct('Quota window ended; a new physical attempt is required.');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: ProxymaErrorCode::NeedsReplan,
            category: ErrorCategory::Conflict,
            retryable: true,
        );
    }
}
