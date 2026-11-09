<?php

declare(strict_types=1);

namespace Proxyma\Domain\Catalog;

use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;

final readonly class UpsertRouteProfileCommand
{
    /**
     * @param  list<Uuid>  $memberRouteIds
     */
    public function __construct(
        public Uuid $profileId,
        public TenantKey $tenantKey,
        public string $profileKey,
        public array $memberRouteIds,
        public string $strategyKey,
        public CatalogMutationActor $actor,
    ) {}
}
