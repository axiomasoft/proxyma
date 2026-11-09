<?php

declare(strict_types=1);

namespace Proxyma\Domain\Catalog;

use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;

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
