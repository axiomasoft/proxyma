<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use Packman\Attributes\Api;

/**
 * Closed execution result contract:
 * ExecutionSucceeded | ExecutionDeferred | ExecutionBlocked | ExecutionUnknownAfterAuthorization.
 */
#[Api(since: '1.0')]
interface ExecutionResult {}
