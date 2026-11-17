<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Authorization;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface Authorizer
{
    /** Deny by throwing before reads or writes. Actor/tenant have been checked against TenantContextResolver. No external I/O inside transactions. */
    public function authorize(ActorRef $actor, TenantKey $tenant, string $ability, Uuid $resourceId): void;
}
