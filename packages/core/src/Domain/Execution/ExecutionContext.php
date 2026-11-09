<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;
use Proxyma\Domain\Policy\SubjectChain;

final readonly class ExecutionContext
{
    public function __construct(
        public OperationCorrelation $operation,
        public SubjectChain $subjects,
        public Duration $remainingSlice,
        public int $monotonicDeadlineNs,
    ) {
        if (! $operation->owner->tenantKey->equals($subjects->tenant)) {
            throw new InvalidArgumentException('ExecutionContext owner tenant and subject chain tenant must match.');
        }

        if ($monotonicDeadlineNs <= 0) {
            throw new InvalidArgumentException('Monotonic deadline must be a positive nanosecond timestamp.');
        }
    }

    public function canFitDuration(Duration $required): bool
    {
        return $this->remainingSlice->milliseconds >= $required->milliseconds;
    }
}
