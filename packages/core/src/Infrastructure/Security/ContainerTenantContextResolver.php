<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Security;

use Illuminate\Contracts\Container\Container;
use Proxyma\Contracts\TenantContextResolver;
use Proxyma\Domain\Policy\TenantContext;

/** @internal Не удерживает scoped resolver между заданиями долгоживущего worker. */
final readonly class ContainerTenantContextResolver implements TenantContextResolver
{
    public function __construct(private Container $container) {}

    public function resolve(): TenantContext
    {
        return $this->container->make(TenantContextResolver::class)->resolve();
    }
}
