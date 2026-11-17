<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use Packman\Attributes\Api;
use Proxyma\Domain\Outcomes\ResolvedOutcome;

/** A completed physical fact; admission and completion have already run in the native engine. */
#[Api(since: '1.0')]
final readonly class PhysicalExecutionResult
{
    public function __construct(
        public Uuid $physicalAttemptId,
        public Uuid $routeId,
        public AttemptResult $result,
        public ResolvedOutcome $outcome,
        public RequestTimeoutPolicy $timeouts,
        public ?ExecutionUnknownAfterAuthorization $unknown = null,
    ) {}
}
