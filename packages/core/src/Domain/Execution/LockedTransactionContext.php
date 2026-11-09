<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;
use LogicException;

final readonly class LockedTransactionContext
{
    public function __construct(
        public string $connectionName,
        public string $outerTransactionToken,
        public int $monotonicSampledBeforeTxNs,
        public int $nestingLevel = 1,
    ) {
        if ($connectionName === '') {
            throw new InvalidArgumentException('LockedTransactionContext requires a non-empty connectionName.');
        }

        if ($outerTransactionToken === '') {
            throw new InvalidArgumentException('LockedTransactionContext requires a non-empty outerTransactionToken.');
        }

        if ($monotonicSampledBeforeTxNs <= 0) {
            throw new InvalidArgumentException('LockedTransactionContext requires a positive monotonicSampledBeforeTxNs.');
        }

        if ($nestingLevel < 1) {
            throw new InvalidArgumentException('LockedTransactionContext requires nestingLevel >= 1.');
        }
    }

    public function assertConnection(string $expectedConnectionName): void
    {
        if ($this->connectionName !== $expectedConnectionName) {
            throw new LogicException("Transaction connection mismatch: expected '{$expectedConnectionName}', got '{$this->connectionName}'.");
        }
    }

    public function withNestingLevel(int $nestingLevel): self
    {
        return new self(
            connectionName: $this->connectionName,
            outerTransactionToken: $this->outerTransactionToken,
            monotonicSampledBeforeTxNs: $this->monotonicSampledBeforeTxNs,
            nestingLevel: $nestingLevel,
        );
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('LockedTransactionContext cannot be serialized.');
    }
}
