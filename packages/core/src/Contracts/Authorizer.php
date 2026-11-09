<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;

interface Authorizer
{
    /** Deny by throwing before any catalog writes. Tenant is supplied by a trusted caller. */
    public function authorize(ActorRef $actor, TenantKey $tenant, string $ability, Uuid $resourceId): void;
}
