<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Proxyma\Domain\Policy\TenantContext;

interface TenantContextResolver
{
    /** Resolve authenticated job/request scope independently of command or request-body fields; throw if absent. */
    public function resolve(): TenantContext;
}
