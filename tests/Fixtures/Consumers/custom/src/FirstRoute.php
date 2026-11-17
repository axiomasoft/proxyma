<?php

declare(strict_types=1);

namespace Consumer;

use Proxyma\Contracts\Routing\RouteSelectionStrategy;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Execution\RouteSelectionContext;
use Proxyma\Domain\Execution\WireRequest;

final class FirstRoute implements RouteSelectionStrategy
{
    public function key(): string
    {
        return 'consumer.first';
    }

    public function select(array $candidates, WireRequest $request, RouteSelectionContext $context): ?Route
    {
        return ($candidates[0] ?? null)?->route;
    }
}
