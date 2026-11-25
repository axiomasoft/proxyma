<?php

declare(strict_types=1);

namespace Proxyma\Application\Catalog;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;
use Proxyma\Errors\ProxymaErrorCode;

/** Thrown when persisting catalog records or audit trail entries fails. */
#[Api(since: '1.0')]
final class CatalogStorageFailure extends CodedException
{
    public function __construct()
    {
        parent::__construct('Catalog storage or audit failed.');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: ProxymaErrorCode::CatalogStorageFailure,
            category: ErrorCategory::Internal,
        );
    }
}
