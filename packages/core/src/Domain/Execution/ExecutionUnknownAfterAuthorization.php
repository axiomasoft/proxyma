<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

/**
 * Returned when a send permit was durably authorized (quota consumed),
 * but physical completion or remote outcome could not be confirmed.
 */
final readonly class ExecutionUnknownAfterAuthorization implements ExecutionResult
{
    public function __construct(
        public Uuid $operationId,
        public Uuid $attemptId,
        public Uuid $permitId,
        public ContentHash $wireFingerprint,
        public ?TransportFailure $failure = null,
    ) {}
}
