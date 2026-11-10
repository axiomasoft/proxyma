<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use Closure;
use LogicException;

/**
 * Single-use, non-serializable send capability accepted by PhysicalTransport::sendOnce.
 * Minted exclusively after the outermost SQL transaction commits via CommitLatch + ClaimCandidate.
 */
final class AuthorizedWireRequest implements \JsonSerializable
{
    private bool $consumed = false;

    private function __construct(
        public readonly WireRequest $wire,
        public readonly AuthorizedWireSnapshot $snapshot,
        public readonly int $latestStartMonotonicNs,
        private readonly Closure $assertOutsideTransaction,
        private readonly Closure $clock,
        private readonly Closure $onLate,
        public readonly ?SealedWire $prepared,
    ) {}

    /** @internal Low-level latch primitive; application callers use ClaimSend::activate. */
    public static function activate(
        ClaimCandidate $candidate,
        CommitLatch $latch,
        int $currentTransactionLevel,
        WireRequest $wire,
        AuthorizedWireSnapshot $snapshot,
        ?int $nowMonotonicNs = null,
    ): self {
        $authority = $candidate->activationAuthority();
        $authority->assertActivation($candidate, $snapshot);
        $assertOutsideTransaction = $authority->assertOutsideTransaction(...);
        $clock = $authority->nowMonotonicNs(...);
        $onLate = fn () => $authority->lateRefusal($candidate);
        $prepared = $authority->preparedWire($candidate);
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

        $currentNs = $clock();
        if ($currentNs > $latestStartNs) {
            $onLate();
            throw new LogicException('Authorized send window expired before capability activation.');
        }

        return new self($wire, $snapshot, $latestStartNs, $assertOutsideTransaction, $clock, $onLate, $prepared);
    }

    public function consume(?int $nowMonotonicNs = null): void
    {
        ($this->assertOutsideTransaction)();
        if ($nowMonotonicNs !== null) {
            throw new LogicException('Sealed capability does not accept a caller clock.');
        }
        if ($this->consumed) {
            throw new LogicException('Authorized wire capability was already consumed.');
        }

        $this->consumed = true;
        $currentNs = ($this->clock)();
        if ($currentNs > $this->latestStartMonotonicNs) {
            ($this->onLate)();
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

    public function __unserialize(array $data): void
    {
        throw new LogicException('AuthorizedWireRequest cannot be reconstructed.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('AuthorizedWireRequest cannot be serialized.');
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
