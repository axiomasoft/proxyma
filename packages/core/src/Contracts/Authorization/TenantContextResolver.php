<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Authorization;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Domain\Policy\TenantContext;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface TenantContextResolver
{
    /** Resolve authenticated job/request scope independently of command or request-body fields; throw if absent. */
    public function resolve(): TenantContext;
}
