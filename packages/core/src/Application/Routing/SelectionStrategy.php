<?php

declare(strict_types=1);

namespace Proxyma\Application\Routing;

use InvalidArgumentException;
use Proxyma\Contracts\RandomIndex;
use Proxyma\Contracts\RouteSelectionStrategy;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Execution\RouteSelectionContext;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Routing\Candidate;

final readonly class SelectionStrategy implements RouteSelectionStrategy
{
    public function __construct(private string $strategyKey, private RandomIndex $random)
    {
        if (! in_array($strategyKey, ['uniform_random', 'least_recent_authorized', 'budget_aware_random'], true)) {
            throw new InvalidArgumentException('Unknown route strategy.');
        }
    }

    public function key(): string
    {
        return $this->strategyKey;
    }

    public function select(array $candidates, WireRequest $request, RouteSelectionContext $context): ?Route
    {
        $candidates = array_values(array_filter($candidates, static fn (Candidate $c): bool => $c->eligible()));
        if ($candidates === []) {
            return null;
        }
        usort($candidates, static fn (Candidate $a, Candidate $b): int => strcmp($a->routeId->value, $b->routeId->value));
        if ($this->strategyKey === 'least_recent_authorized') {
            usort($candidates, static function (Candidate $a, Candidate $b): int {
                if ($a->lastAuthorizedAt === null || $b->lastAuthorizedAt === null) {
                    return ($a->lastAuthorizedAt === null ? 0 : 1) <=> ($b->lastAuthorizedAt === null ? 0 : 1)
                        ?: strcmp($a->routeId->value, $b->routeId->value);
                }

                return $a->lastAuthorizedAt <=> $b->lastAuthorizedAt ?: strcmp($a->routeId->value, $b->routeId->value);
            });

            return $candidates[0]->route;
        }
        if ($this->strategyKey === 'budget_aware_random') {
            $ready = array_values(array_filter($candidates, static fn (Candidate $c): bool => $c->budgetReady));
            if ($ready !== []) {
                $candidates = $ready;
            }
        }
        $index = $this->random->next(count($candidates));
        if ($index < 0 || $index >= count($candidates)) {
            throw new InvalidArgumentException('Random index is outside candidate range.');
        }

        return $candidates[$index]->route;
    }
}
