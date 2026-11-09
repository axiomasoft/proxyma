<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

/**
 * Closed execution result contract:
 * ExecutionSucceeded | ExecutionDeferred | ExecutionBlocked | ExecutionUnknownAfterAuthorization.
 */
interface ExecutionResult {}
