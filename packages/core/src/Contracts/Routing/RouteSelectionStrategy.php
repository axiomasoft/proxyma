<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Routing;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Execution\RouteSelectionContext;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Routing\Candidate;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface RouteSelectionStrategy
{
    public function key(): string;

    /**
     * @param  list<Candidate>  $candidates
     */
    public function select(array $candidates, WireRequest $request, RouteSelectionContext $context): ?Route;
}
