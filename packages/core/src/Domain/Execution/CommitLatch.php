<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

/**
 * Outermost SQL transaction commit latch.
 * Savepoint releases never mark this latch committed; rollback permanently invalidates it.
 */
final class CommitLatch
{
    private const string STATE_PENDING = 'pending';

    private const string STATE_COMMITTED = 'committed';

    private const string STATE_ROLLED_BACK = 'rolled_back';

    private string $state = self::STATE_PENDING;

    public function __construct(
        public readonly string $connectionName,
        public readonly string $outerTransactionToken,
        public readonly int $monotonicSampledBeforeTxNs,
    ) {
        if ($connectionName === '') {
            throw new InvalidArgumentException('CommitLatch connectionName must not be empty.');
        }

        if ($outerTransactionToken === '') {
            throw new InvalidArgumentException('CommitLatch outerTransactionToken must not be empty.');
        }

        if ($monotonicSampledBeforeTxNs <= 0) {
            throw new InvalidArgumentException('CommitLatch monotonicSampledBeforeTxNs must be positive.');
        }
    }

    public function issueCandidate(
        Uuid $permitId,
        Uuid $operationId,
        Uuid $physicalAttemptId,
        ContentHash $wireFingerprint,
        DateTimeImmutable $authorizedAt,
        DateTimeImmutable $expiresAt,
        int $currentTransactionLevel,
    ): ClaimCandidate {
        if ($currentTransactionLevel < 1) {
            throw new LogicException('ClaimCandidate can only be issued inside an active SQL transaction.');
        }

        if ($this->state !== self::STATE_PENDING) {
            throw new LogicException('Cannot issue ClaimCandidate on a finished or rolled-back CommitLatch.');
        }

        return ClaimCandidate::fromLatch(
            latch: $this,
            permitId: $permitId,
            operationId: $operationId,
            physicalAttemptId: $physicalAttemptId,
            wireFingerprint: $wireFingerprint,
            authorizedAt: $authorizedAt,
            expiresAt: $expiresAt,
        );
    }

    public function onSavepointReleased(): void
    {
        if ($this->state !== self::STATE_PENDING) {
            throw new LogicException('Savepoint cannot release on a non-pending CommitLatch.');
        }
    }

    public function markOutermostCommitted(int $currentTransactionLevel): void
    {
        if ($currentTransactionLevel !== 0) {
            throw new LogicException('CommitLatch can only commit when outermost transactionLevel reaches 0.');
        }

        if ($this->state !== self::STATE_PENDING) {
            throw new LogicException("Cannot mark CommitLatch committed from state '{$this->state}'.");
        }

        $this->state = self::STATE_COMMITTED;
    }

    public function markRolledBack(): void
    {
        $this->state = self::STATE_ROLLED_BACK;
    }

    public function isCommitted(): bool
    {
        return $this->state === self::STATE_COMMITTED;
    }

    public function isRolledBack(): bool
    {
        return $this->state === self::STATE_ROLLED_BACK;
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('CommitLatch cannot be serialized.');
    }

    public function __clone(): void
    {
        throw new LogicException('CommitLatch cannot be cloned.');
    }
}
