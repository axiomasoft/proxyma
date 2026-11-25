<?php

declare(strict_types=1);

namespace Proxyma\Application\Catalog;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;
use Proxyma\Errors\ProxymaErrorCode;

/** Thrown when a catalog command violates structural or state-transition invariants. */
#[Api(since: '1.0')]
final class CatalogInvalidMutation extends CodedException
{
    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: ProxymaErrorCode::CatalogInvalidMutation,
            category: ErrorCategory::Validation,
        );
    }
}
