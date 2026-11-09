<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Execution\Uuid;

final readonly class UpdatePolicyCommand
{
    public function __construct(
        public Uuid $headId,
        public TenantKey $tenant,
        public PolicyScope $scope,
        public string $scopeId,
        public string $serializedPatch,
        public CatalogMutationActor $actor,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D', $scopeId) !== 1
            || ($scope === PolicyScope::Tenant && $scopeId !== $tenant->value)
            || ($scope === PolicyScope::Global && (! $tenant->isSystem() || $scopeId !== TenantKey::SYSTEM))) {
            throw new InvalidSettingPatch;
        }
    }

    public function scopeKey(): string
    {
        return $this->scope->value.':'.$this->scopeId;
    }
}
