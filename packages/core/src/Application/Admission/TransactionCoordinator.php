<?php

declare(strict_types=1);

namespace Proxyma\Application\Admission;

use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use InvalidArgumentException;
use LogicException;
use Proxyma\Contracts\TransactionCoordinator as CoordinatorContract;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Execution\AuthorizedWireRequest;
use Proxyma\Domain\Execution\AuthorizedWireSnapshot;
use Proxyma\Domain\Execution\ClaimCandidate;
use Proxyma\Domain\Execution\ClaimDenied;
use Proxyma\Domain\Execution\CommitLatch;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\ExecutionBlocked;
use Proxyma\Domain\Execution\ExecutionDeferred;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\PermitWait;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Infrastructure\Database\ConnectionScope;
use WeakMap;

final class TransactionCoordinator implements CoordinatorContract
{
    /** @var WeakMap<DatabaseTransactionRecord, CommitLatch> */
    private WeakMap $latches;

    /** @var WeakMap<ClaimCandidate, CommitLatch> */
    private WeakMap $candidates;

    /** @var Closure(): int */
    private readonly Closure $clock;

    public function __construct(public readonly ConnectionScope $scope, ?Closure $monotonicClock = null)
    {
        $this->latches = new WeakMap;
        $this->candidates = new WeakMap;
        $this->clock = $monotonicClock ?? static fn (): int => hrtime(true);
    }

    public function connectionName(): string
    {
        return $this->scope->connection->getName();
    }

    public function transactionLevel(): int
    {
        return $this->scope->connection->transactionLevel();
    }

    public function inTransaction(): bool
    {
        return $this->transactionLevel() > 0;
    }

    public function run(Closure $operation, int $maxAttempts = 3): mixed
    {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('TransactionCoordinator requires at least one attempt.');
        }
        $sample = ($this->clock)();
        if ($this->inTransaction()) {
            return $this->locked($this->scope->connection, $operation, $sample);
        }

        return $this->scope->connection->transaction(fn (): mixed => $this->locked($this->scope->connection, $operation, $sample), $maxAttempts);
    }

    /**
     * @template T
     *
     * @param  Closure(LockedTransactionContext): T  $operation
     * @return T
     */
    public function locked(Connection $caller, Closure $operation, ?int $sampleBeforeJoinNs = null): mixed
    {
        $this->scope->assertSame($caller);
        $root = $this->scope->root();
        if (! isset($this->latches[$root])) {
            $sample = min($sampleBeforeJoinNs ?? PHP_INT_MAX, $this->scope->monotonicBeforeRootNs());
            $latch = new CommitLatch($this->connectionName(), Uuid::random()->value, $sample);
            $this->latches[$root] = $latch;
            // Регистрируем на точном root этой connection, а не на последней глобальной transaction.
            $root->addCallback(function () use ($latch): void {
                $this->scope->assertOutsideTransaction();
                $latch->markOutermostCommitted(0);
            });
            $root->addCallbackForRollback($latch->markRolledBack(...));
        }
        $latch = $this->latches[$root];
        if ($latch->isRolledBack()) {
            throw new AdmissionRejected('transaction_rejected');
        }
        $context = new LockedTransactionContext($this->connectionName(), $latch->outerTransactionToken,
            $latch->monotonicSampledBeforeTxNs, $this->transactionLevel());
        $this->scope->register($context);
        $result = $operation($context);
        $this->scope->assertActive($context);
        if ($result === false || $result instanceof ClaimDenied || $result instanceof ExecutionBlocked
            || $result instanceof ExecutionDeferred || $result instanceof PermitWait) {
            throw new AdmissionRejected($result === false ? 'false_result' : 'denied_result');
        }

        return $result;
    }

    public function issueCandidate(Uuid $permitId, Uuid $operationId, Uuid $physicalAttemptId, ContentHash $wireFingerprint,
        DateTimeImmutable $authorizedAt, DateTimeImmutable $expiresAt, LockedTransactionContext $context): ClaimCandidate
    {
        $this->scope->assertActive($context);
        $latch = $this->latches[$this->scope->root()] ?? throw new LogicException('No active commit latch.');
        if ($latch->outerTransactionToken !== $context->outerTransactionToken) {
            throw new LogicException('Transaction token mismatch.');
        }
        $candidate = $latch->issueCandidate($permitId, $operationId, $physicalAttemptId, $wireFingerprint,
            $authorizedAt, $expiresAt, $this->transactionLevel());
        $this->candidates[$candidate] = $latch;
        $this->scope->current()->addCallbackForRollback($candidate->invalidateOnRollback(...));

        return $candidate;
    }

    public function activate(ClaimCandidate $candidate, WireRequest $request, AuthorizedWireSnapshot $snapshot, ?int $nowMonotonicNs = null): AuthorizedWireRequest
    {
        $this->scope->assertOutsideTransaction();
        $latch = $this->candidates[$candidate] ?? throw new LogicException('Unknown candidate: foreign coordinator or process restart.');

        return AuthorizedWireRequest::activate($candidate, $latch, 0, $request, $snapshot, $nowMonotonicNs ?? ($this->clock)());
    }
}
