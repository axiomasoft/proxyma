<?php

declare(strict_types=1);

namespace Proxyma\Application\Routing;

use InvalidArgumentException;
use Proxyma\Contracts\RandomIndex;
use Proxyma\Contracts\RouteSelectionStrategy;

final readonly class StrategyRegistry
{
    /** @var array<string, RouteSelectionStrategy> */
    private array $strategies;

    /** @param list<RouteSelectionStrategy>|null $strategies */
    public function __construct(RandomIndex $random, ?array $strategies = null)
    {
        $strategies ??= array_map(static fn (string $id): SelectionStrategy => new SelectionStrategy($id, $random),
            ['uniform_random', 'least_recent_authorized', 'budget_aware_random']);
        $indexed = [];
        foreach ($strategies as $strategy) {
            if (isset($indexed[$strategy->key()])) {
                throw new InvalidArgumentException('Duplicate strategy ID.');
            }
            $indexed[$strategy->key()] = $strategy;
        }
        $this->strategies = $indexed;
    }

    public function get(string $id): RouteSelectionStrategy
    {
        return $this->strategies[$id] ?? throw new InvalidArgumentException('Unknown route strategy ID.');
    }
}
