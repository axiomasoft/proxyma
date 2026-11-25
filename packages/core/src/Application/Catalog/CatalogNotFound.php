<?php

declare(strict_types=1);

namespace Proxyma\Application\Catalog;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;
use Proxyma\Errors\ProxymaErrorCode;

/** Thrown when a referenced catalog entity cannot be found in the tenant scope. */
#[Api(since: '1.0')]
final class CatalogNotFound extends CodedException
{
    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: ProxymaErrorCode::CatalogNotFound,
            category: ErrorCategory::NotFound,
        );
    }
}
