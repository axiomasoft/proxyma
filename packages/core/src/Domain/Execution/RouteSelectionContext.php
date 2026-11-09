<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;

final readonly class RouteSelectionContext
{
    /** @var list<Uuid> */
    public array $triedRouteIds;

    /**
     * @param  list<Uuid>  $triedRouteIds
     */
    public function __construct(
        array $triedRouteIds = [],
        public ?Uuid $previousRouteId = null,
        public bool $preferUntried = false,
        public int $maxDistinctRoutes = 1,
    ) {
        if ($maxDistinctRoutes < 1) {
            throw new InvalidArgumentException('maxDistinctRoutes must be at least 1.');
        }

        $this->triedRouteIds = $triedRouteIds;
    }
}
