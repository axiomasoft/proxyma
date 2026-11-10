<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use Proxyma\Contracts\ClaimActivationAuthority;

/**
 * Inert, non-serializable claim candidate returned by claimSendLocked inside a transaction.
 * Cannot be passed to PhysicalTransport directly; must be activated after outermost commit.
 */
final class ClaimCandidate implements \JsonSerializable
{
    public readonly DateTimeImmutable $authorizedAt;

    public readonly DateTimeImmutable $expiresAt;

    public readonly int $authorizedDurationNs;

    public readonly int $monotonicSampledBeforeTxNs;

    public readonly string $connectionName;

    public readonly string $outerTransactionToken;

    private bool $activated = false;

    private bool $invalidated = false;

    private function __construct(
        private readonly CommitLatch $latch,
        public readonly Uuid $permitId,
        public readonly Uuid $operationId,
        public readonly Uuid $physicalAttemptId,
        public readonly ContentHash $wireFingerprint,
        DateTimeImmutable $authorizedAt,
        DateTimeImmutable $expiresAt,
        private ?ClaimActivationAuthority $activationAuthority = null,
    ) {
        $utc = new DateTimeZone('UTC');
        $normalizedAuth = $authorizedAt->setTimezone($utc);
        $normalizedExp = $expiresAt->setTimezone($utc);

        $durationNs = (($normalizedExp->getTimestamp() - $normalizedAuth->getTimestamp()) * 1_000_000_000)
            + (((int) $normalizedExp->format('u') - (int) $normalizedAuth->format('u')) * 1000);

        if ($durationNs <= 0) {
            throw new InvalidArgumentException('ClaimCandidate expiresAt must be strictly after authorizedAt.');
        }

        $this->authorizedAt = $normalizedAuth;
        $this->expiresAt = $normalizedExp;
        $this->authorizedDurationNs = $durationNs;
        $this->monotonicSampledBeforeTxNs = $latch->monotonicSampledBeforeTxNs;
        $this->connectionName = $latch->connectionName;
        $this->outerTransactionToken = $latch->outerTransactionToken;
    }

    /**
     * @internal Issued exclusively by CommitLatch::issueCandidate inside an active transaction.
     */
    public static function fromLatch(
        CommitLatch $latch,
        Uuid $permitId,
        Uuid $operationId,
        Uuid $physicalAttemptId,
        ContentHash $wireFingerprint,
        DateTimeImmutable $authorizedAt,
        DateTimeImmutable $expiresAt,
        ?ClaimActivationAuthority $activationAuthority = null,
    ): self {
        return new self(
            latch: $latch,
            permitId: $permitId,
            operationId: $operationId,
            physicalAttemptId: $physicalAttemptId,
            wireFingerprint: $wireFingerprint,
            authorizedAt: $authorizedAt,
            expiresAt: $expiresAt,
            activationAuthority: $activationAuthority,
        );
    }

    public function conservativeLatestStartNs(int $totalTimeoutWithCleanupMarginNs): int
    {
        if ($totalTimeoutWithCleanupMarginNs < 0) {
            throw new InvalidArgumentException('Timeout margin must be non-negative.');
        }

        return $this->monotonicSampledBeforeTxNs + max(0, $this->authorizedDurationNs - $totalTimeoutWithCleanupMarginNs);
    }

    /** @internal A manually constructed latch has no grant authority. */
    public function activationAuthority(): ClaimActivationAuthority
    {
        return $this->activationAuthority ?? throw new LogicException('Candidate has no local SQL activation authority.');
    }

    public function invalidateOnRollback(): void
    {
        $this->invalidated = true;
    }

    public function consumeForActivation(CommitLatch $expectedLatch, int $currentTransactionLevel): void
    {
        if ($this->invalidated) {
            throw new LogicException('ClaimCandidate was invalidated by transaction or savepoint rollback.');
        }

        if ($currentTransactionLevel !== 0) {
            throw new LogicException('Cannot activate ClaimCandidate while a SQL transaction is still open.');
        }

        if ($this->latch !== $expectedLatch) {
            throw new LogicException('ClaimCandidate does not belong to the provided CommitLatch.');
        }

        if (! $this->latch->isCommitted()) {
            throw new LogicException('Cannot activate ClaimCandidate from an uncommitted or rolled-back transaction.');
        }

        if ($this->activated) {
            throw new LogicException('ClaimCandidate has already been activated.');
        }

        $this->activated = true;
    }

    public function isActivated(): bool
    {
        return $this->activated;
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('ClaimCandidate cannot be serialized.');
    }

    public function __clone(): void
    {
        throw new LogicException('ClaimCandidate cannot be cloned.');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('ClaimCandidate cannot be reconstructed.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('ClaimCandidate cannot be serialized.');
    }
}
