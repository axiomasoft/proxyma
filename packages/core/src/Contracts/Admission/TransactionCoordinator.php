<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Admission;

use Closure;
use DateTimeImmutable;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Domain\Execution\AuthorizedWireRequest;
use Proxyma\Domain\Execution\AuthorizedWireSnapshot;
use Proxyma\Domain\Execution\ClaimCandidate;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;

/**
 * Composable SQL transaction port.
 * Declares connection identity, tracks outermost CommitLatch (savepoint releases do not activate),
 * and activates a single-use AuthorizedWireRequest only after the outermost transaction commits.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface TransactionCoordinator
{
    public function connectionName(): string;

    public function transactionLevel(): int;

    public function inTransaction(): bool;

    /**
     * Executes $operation(LockedTransactionContext $context) inside a SQL transaction on connectionName().
     * Nested calls join the active outer transaction without retrying or prematurely releasing CommitLatch.
     *
     * @template T
     *
     * @param  Closure(LockedTransactionContext): T  $operation
     * @return T
     */
    public function run(Closure $operation, int $maxAttempts = 3): mixed;

    /** @internal Issuing an inert candidate never substitutes for durable ClaimSend authorization. */
    public function issueCandidate(
        Uuid $permitId,
        Uuid $operationId,
        Uuid $physicalAttemptId,
        ContentHash $wireFingerprint,
        DateTimeImmutable $authorizedAt,
        DateTimeImmutable $expiresAt,
        LockedTransactionContext $context,
    ): ClaimCandidate;

    public function activate(
        ClaimCandidate $candidate,
        WireRequest $request,
        AuthorizedWireSnapshot $snapshot,
        ?int $nowMonotonicNs = null,
    ): AuthorizedWireRequest;
}
