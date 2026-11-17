<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Secrets;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;
use Proxyma\Errors\ProxymaErrorCode;

/** Thrown when persisting, reading, or decrypting stored credentials fails. */
#[Api(since: '1.0')]
final class SecretStorageFailure extends CodedException
{
    public function __construct()
    {
        parent::__construct('Credential storage or decryption failed.');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: ProxymaErrorCode::SecretStorageFailure,
            category: ErrorCategory::Internal,
        );
    }
}
