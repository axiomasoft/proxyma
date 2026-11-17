<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;
use Proxyma\Errors\ProxymaErrorCode;

/** Thrown when persisting policy revisions, bindings, or audit records fails. */
#[Api(since: '1.0')]
final class PolicyStorageFailure extends CodedException
{
    public function __construct()
    {
        parent::__construct('Policy storage or audit failed.');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: ProxymaErrorCode::PolicyStorageFailure,
            category: ErrorCategory::Internal,
        );
    }
}
