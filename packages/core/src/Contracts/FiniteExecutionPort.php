<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use DateTimeImmutable;
use Proxyma\Domain\Execution\Duration;
use Proxyma\Domain\Execution\ExecutionResult;
use Proxyma\Domain\Execution\OutboundRequest;
use Proxyma\Domain\Execution\PhysicalExecutionResult;
use Proxyma\Domain\Execution\RouteSelectionContext;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Execution\Uuid;

/** Consumer mapping/owner composition; generic retry and redirect bounds remain in core. */
interface FiniteExecutionPort
{
    public function executeAttempt(OutboundRequest $request, RouteSelectionContext $routes, int $attempt, int $redirects): PhysicalExecutionResult|ExecutionResult;

    public function redirectTarget(Uuid $from, SafeUrl $destination): ?Uuid;

    public function canStart(Duration $required): bool;

    /** PostgreSQL clock_timestamp(), not a PHP wall-clock authority. */
    public function now(): DateTimeImmutable;
}
