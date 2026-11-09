<?php

declare(strict_types=1);

namespace Proxyma\Domain\Catalog;

use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;

final readonly class RetireCatalogEntityCommand
{
    public function __construct(
        public Uuid $entityId,
        public TenantKey $tenantKey,
        public CatalogMutationActor $actor,
    ) {}
}
