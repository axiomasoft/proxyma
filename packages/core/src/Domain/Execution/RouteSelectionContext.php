<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class RouteSelectionContext
{
    /**
     * @param  list<Uuid>  $triedRouteIds
     */
    public function __construct(
        public array $triedRouteIds = [],
        public ?Uuid $previousRouteId = null,
        public bool $preferUntried = false,
        public int $maxDistinctRoutes = 1,
    ) {
        if ($maxDistinctRoutes < 1) {
            throw new InvalidArgumentException('maxDistinctRoutes must be at least 1.');
        }
    }
}
