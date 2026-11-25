<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class NeedsReplan
{
    /** @var list<string> */
    public array $affectedRoutes;

    /**
     * @param  list<string>  $affectedRoutes
     */
    public function __construct(
        public PinnedPolicySnapshot $pinned,
        public EffectivePolicy $livePolicy,
        public string $reason,
        public ?string $pinnedProfileKey = null,
        public ?string $liveProfileKey = null,
        array $affectedRoutes = [],
    ) {
        if ($reason === '') {
            throw new InvalidArgumentException('NeedsReplan requires a non-empty reason.');
        }

        $routes = array_values(array_unique($affectedRoutes));
        sort($routes, SORT_STRING);
        $this->affectedRoutes = $routes;
    }
}
