<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Security;

use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Contracts\TenantContextResolver;
use Proxyma\Domain\Policy\TenantContext;

final class UnconfiguredTenantContextResolver implements TenantContextResolver
{
    public function resolve(): TenantContext
    {
        throw new AuthorizationDenied;
    }
}
