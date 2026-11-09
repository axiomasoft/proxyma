<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use LogicException;

/**
 * Single-use, non-serializable send capability accepted by PhysicalTransport::sendOnce.
 * Minted exclusively after the outermost SQL transaction commits via CommitLatch + ClaimCandidate.
 */
final class AuthorizedWireRequest
{
    private bool $consumed = false;

    private function __construct(
        public readonly WireRequest $wire,
        public readonly AuthorizedWireSnapshot $snapshot,
        public readonly int $latestStartMonotonicNs,
    ) {}

    public static function activate(
        ClaimCandidate $candidate,
        CommitLatch $latch,
        int $currentTransactionLevel,
        WireRequest $wire,
        AuthorizedWireSnapshot $snapshot,
        ?int $nowMonotonicNs = null,
    ): self {
        if (! $wire->operationId->equals($candidate->operationId) || ! $wire->physicalAttemptId->equals($candidate->physicalAttemptId)) {
            throw new LogicException('WireRequest identity does not match the ClaimCandidate.');
        }

        if (! $wire->fingerprint()->equals($candidate->wireFingerprint) || ! $snapshot->fullFingerprint->equals($candidate->wireFingerprint)) {
            throw new LogicException('WireRequest or AuthorizedWireSnapshot fingerprint does not match the ClaimCandidate.');
        }

        if (! $snapshot->permitId->equals($candidate->permitId)) {
            throw new LogicException('AuthorizedWireSnapshot permitId does not match the ClaimCandidate.');
        }

        $candidate->consumeForActivation($latch, $currentTransactionLevel);

        $latestStartNs = min(
            $snapshot->latestStartMonotonicNs,
            $candidate->conservativeLatestStartNs($wire->timeouts->totalWithCleanupMarginNs()),
        );

        $currentNs = $nowMonotonicNs ?? hrtime(true);
        if ($currentNs > $latestStartNs) {
            throw new LogicException('Authorized send window expired before capability activation.');
        }

        return new self($wire, $snapshot, $latestStartNs);
    }

    public function consume(?int $nowMonotonicNs = null): void
    {
        if ($this->consumed) {
            throw new LogicException('Authorized wire capability was already consumed.');
        }

        $currentNs = $nowMonotonicNs ?? hrtime(true);
        if ($currentNs > $this->latestStartMonotonicNs) {
            throw new LogicException('Authorized wire capability expired before physical send.');
        }

        $this->consumed = true;
    }

    public function isConsumed(): bool
    {
        return $this->consumed;
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('AuthorizedWireRequest cannot be serialized.');
    }

    public function __clone(): void
    {
        throw new LogicException('AuthorizedWireRequest cannot be cloned.');
    }

    /**
     * @return array{operation_id: string, physical_attempt_id: string, permit_id: string, consumed: bool}
     */
    public function __debugInfo(): array
    {
        return [
            'operation_id' => $this->wire->operationId->value,
            'physical_attempt_id' => $this->wire->physicalAttemptId->value,
            'permit_id' => $this->snapshot->permitId->value,
            'consumed' => $this->consumed,
        ];
    }
}
