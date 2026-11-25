<?php

declare(strict_types=1);

namespace Proxyma\Domain\Catalog;

use Packman\Attributes\Api;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;

#[Api(since: '1.0')]
final readonly class RetireCatalogEntityCommand
{
    public function __construct(
        public Uuid $entityId,
        public TenantKey $tenantKey,
        public CatalogMutationActor $actor,
    ) {}
}
