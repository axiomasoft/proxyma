<?php

declare(strict_types=1);

namespace Proxyma\Domain\Catalog;

use Packman\Attributes\Api;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;

#[Api(since: '1.0')]
final readonly class ShareRouteCommand
{
    public function __construct(
        public Uuid $grantId,
        public TenantKey $owner,
        public TenantKey $consumer,
        public Uuid $routeId,
        public CatalogMutationActor $actor,
    ) {}
}
