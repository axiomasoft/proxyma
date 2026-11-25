<?php

declare(strict_types=1);

namespace Proxyma\Domain\Catalog;

use Packman\Attributes\Api;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\BudgetConstraint;
use Proxyma\Domain\Policy\TenantKey;

#[Api(since: '1.0')]
final readonly class UpsertTargetCommand
{
    public function __construct(
        public Uuid $targetId,
        public TenantKey $tenantKey,
        public string $targetKey,
        public CanonicalOrigin $origin,
        public string $destinationPolicyKey,
        public CatalogMutationActor $actor,
        /** @var list<BudgetConstraint> */
        public array $budgets = [],
    ) {}
}
