<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;
use Proxyma\Errors\ProxymaErrorCode;

/** Thrown on revision or idempotency conflicts during policy or binding updates. */
#[Api(since: '1.0')]
final class PolicyConflict extends CodedException
{
    public function __construct()
    {
        parent::__construct('Policy revision or idempotency conflict.');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: ProxymaErrorCode::PolicyConflict,
            category: ErrorCategory::Conflict,
        );
    }
}
