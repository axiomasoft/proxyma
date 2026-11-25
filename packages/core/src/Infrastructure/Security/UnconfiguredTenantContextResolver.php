<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Security;

use Packman\Attributes\Api;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Contracts\Authorization\TenantContextResolver;
use Proxyma\Domain\Policy\TenantContext;

#[Api(since: '1.0')]
final class UnconfiguredTenantContextResolver implements TenantContextResolver
{
    public function resolve(): TenantContext
    {
        throw new AuthorizationDenied;
    }
}
