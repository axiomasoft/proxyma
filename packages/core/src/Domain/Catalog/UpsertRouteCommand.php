<?php

declare(strict_types=1);

namespace Proxyma\Domain\Catalog;

use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\BudgetConstraint;
use Proxyma\Domain\Policy\TenantKey;

final readonly class UpsertRouteCommand
{
    public function __construct(
        public Uuid $routeId,
        public TenantKey $tenantKey,
        public string $routeKey,
        public RouteKind $kind,
        public ?Uuid $endpointId,
        public bool $enabled,
        public EgressIdentity $egress,
        public ?string $independenceGroup,
        public bool $sharedGrant,
        public CatalogMutationActor $actor,
        /** @var list<BudgetConstraint> */
        public array $budgets = [],
    ) {}
}
