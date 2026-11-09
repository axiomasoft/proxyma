<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use Proxyma\Domain\Catalog\ActorRef;

final readonly class TenantContext
{
    public function __construct(public TenantKey $tenant, public ActorRef $actor) {}
}
