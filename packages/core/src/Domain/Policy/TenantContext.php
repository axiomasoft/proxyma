<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use Packman\Attributes\Api;
use Proxyma\Domain\Catalog\ActorRef;

#[Api(since: '1.0')]
final readonly class TenantContext
{
    public function __construct(public TenantKey $tenant, public ActorRef $actor) {}
}
