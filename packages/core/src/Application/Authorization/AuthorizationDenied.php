<?php

declare(strict_types=1);

namespace Proxyma\Application\Authorization;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;
use Proxyma\Errors\ProxymaErrorCode;

/** Thrown when an actor is denied catalog, binding, or policy access. */
#[Api(since: '1.0')]
final class AuthorizationDenied extends CodedException
{
    public function __construct()
    {
        parent::__construct('Catalog access denied.');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: ProxymaErrorCode::AuthorizationDenied,
            category: ErrorCategory::Authorization,
        );
    }
}
