<?php

declare(strict_types=1);

namespace Proxyma\Domain\Catalog;

use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;

final readonly class SetRouteEnabledCommand
{
    public function __construct(
        public Uuid $routeId,
        public TenantKey $tenantKey,
        public bool $enabled,
        public CatalogMutationActor $actor,
    ) {}
}
