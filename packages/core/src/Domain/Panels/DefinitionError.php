<?php

declare(strict_types=1);

namespace Proxyma\Domain\Panels;

use InvalidArgumentException;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\DescribesError;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Proxyma\Errors\ProxymaErrorCode;

/** Thrown when panel, slot, resolver, or extension definitions fail validation. */
#[Api(since: '1.0')]
final class DefinitionError extends InvalidArgumentException implements DescribesError
{
    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: ProxymaErrorCode::DefinitionError,
            category: ErrorCategory::Validation,
        );
    }
}
