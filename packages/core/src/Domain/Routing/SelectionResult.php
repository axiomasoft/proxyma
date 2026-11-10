<?php

declare(strict_types=1);

namespace Proxyma\Domain\Routing;

use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Execution\RouteSelection;

final readonly class SelectionResult
{
    /** @param array<string, string> $selectionExclusions */
    public function __construct(
        public CandidateSnapshot $snapshot,
        public ?Route $route,
        public ?RouteSelection $selection,
        public string $reason,
        public array $selectionExclusions = [],
    ) {}
}
