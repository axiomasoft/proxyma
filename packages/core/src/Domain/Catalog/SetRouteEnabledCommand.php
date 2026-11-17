<?php

declare(strict_types=1);

namespace Proxyma\Domain\Catalog;

use Packman\Attributes\Api;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;

#[Api(since: '1.0')]
final readonly class SetRouteEnabledCommand
{
    public function __construct(
        public Uuid $routeId,
        public TenantKey $tenantKey,
        public bool $enabled,
        public CatalogMutationActor $actor,
    ) {}
}
