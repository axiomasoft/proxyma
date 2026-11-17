<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use InvalidArgumentException;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\DescribesError;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Proxyma\Errors\ProxymaErrorCode;

/** Thrown when a setting descriptor or patch operation fails validation. */
#[Api(since: '1.0')]
final class InvalidSettingPatch extends InvalidArgumentException implements DescribesError
{
    public function __construct()
    {
        parent::__construct('Invalid policy patch or setting descriptor.');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: ProxymaErrorCode::InvalidSettingPatch,
            category: ErrorCategory::Validation,
        );
    }
}
