<?php

declare(strict_types=1);

namespace Proxyma\Domain\Catalog;

use DateTimeImmutable;
use InvalidArgumentException;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;

final readonly class SharedRouteGrant
{
    public function __construct(
        public Uuid $id,
        public TenantKey $owner,
        public TenantKey $consumer,
        public Uuid $routeId,
        public int $revision = 1,
        public ?DateTimeImmutable $retiredAt = null,
    ) {
        if ($owner->equals($consumer) || $revision < 1) {
            throw new InvalidArgumentException('Shared grant requires distinct owner/consumer and a positive revision.');
        }
    }

    public function permits(Route $route, TenantKey $consumer): bool
    {
        return $this->retiredAt === null && $this->owner->equals($route->tenantKey)
            && $this->consumer->equals($consumer) && $this->routeId->equals($route->id);
    }
}
