<?php

declare(strict_types=1);

namespace Proxyma\Domain\Catalog;

use InvalidArgumentException;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;

final readonly class RouteProfile
{
    /** @var list<Uuid> */
    public array $memberRouteIds;

    /**
     * @param  list<Uuid>  $memberRouteIds
     */
    public function __construct(
        public Uuid $id,
        public TenantKey $tenantKey,
        public string $profileKey,
        array $memberRouteIds,
        public string $strategyKey = 'uniform_random',
        public int $revision = 1,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D', $profileKey) !== 1) {
            throw new InvalidArgumentException('Profile key must be a non-empty canonical identifier.');
        }

        if (preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $strategyKey) !== 1) {
            throw new InvalidArgumentException('Strategy key must be a valid registered identifier.');
        }

        if ($revision < 1) {
            throw new InvalidArgumentException('Profile revision must be at least 1.');
        }

        $deduplicated = [];
        $seen = [];

        foreach ($memberRouteIds as $routeId) {
            if (! isset($seen[$routeId->value])) {
                $seen[$routeId->value] = true;
                $deduplicated[] = $routeId;
            }
        }

        $this->memberRouteIds = $deduplicated;
    }

    public function isEmpty(): bool
    {
        return $this->memberRouteIds === [];
    }

    public function containsRoute(Uuid $routeId): bool
    {
        foreach ($this->memberRouteIds as $memberId) {
            if ($memberId->equals($routeId)) {
                return true;
            }
        }

        return false;
    }

    public function allowsRoute(Route $route, bool $directAllowedByPolicy = false): bool
    {
        if ($this->isEmpty() || ! $route->enabled || $route->isRetired()) {
            return false;
        }

        if (! $route->isAccessibleBy($this->tenantKey)) {
            return false;
        }

        if (! $this->containsRoute($route->id)) {
            return false;
        }

        if ($route->kind === RouteKind::Direct && ! $directAllowedByPolicy) {
            return false;
        }

        return true;
    }
}
