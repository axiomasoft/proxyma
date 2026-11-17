<?php

declare(strict_types=1);

namespace Proxyma\Domain\Catalog;

use DateTimeImmutable;
use InvalidArgumentException;
use Packman\Attributes\Api;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;

#[Api(since: '1.0')]
final readonly class Route
{
    public function __construct(
        public Uuid $id,
        public TenantKey $tenantKey,
        public string $routeKey,
        public RouteKind $kind,
        public ?Uuid $endpointId,
        public bool $enabled,
        public EgressIdentity $egress,
        public ?string $independenceGroup = null,
        public int $credentialVersion = 1,
        public int $revision = 1,
        public bool $sharedGrant = false,
        public ?DateTimeImmutable $retiredAt = null,
        public ?SharedRouteGrant $accessGrant = null,
        public ?int $endpointRevision = null,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D', $routeKey) !== 1) {
            throw new InvalidArgumentException('Route key must be a non-empty canonical identifier.');
        }

        if (($kind === RouteKind::Proxy) !== ($endpointId !== null)) {
            throw new InvalidArgumentException('Route kind and endpoint link must satisfy strict XOR (proxy requires endpoint, direct forbids endpoint).');
        }

        if ($independenceGroup !== null && preg_match('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D', $independenceGroup) !== 1) {
            throw new InvalidArgumentException('Independence group must be a valid canonical identifier when provided.');
        }

        if ($egress->key === $routeKey || ($independenceGroup !== null && $egress->key === $independenceGroup)) {
            throw new InvalidArgumentException('Egress identity must not be conflated with route key or independence group.');
        }

        if ($credentialVersion < 1 || $revision < 1 || ($endpointRevision !== null && $endpointRevision < 1)) {
            throw new InvalidArgumentException('Route credential version and revision must be at least 1.');
        }
    }

    public function isAccessibleBy(TenantKey $callerTenant): bool
    {
        return $this->tenantKey->equals($callerTenant) || ($this->accessGrant?->permits($this, $callerTenant) ?? false);
    }

    public function isRetired(): bool
    {
        return $this->retiredAt !== null;
    }
}
