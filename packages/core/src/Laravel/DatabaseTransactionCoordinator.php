<?php

declare(strict_types=1);

namespace Proxyma\Laravel;

use Closure;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Proxyma\Contracts\TransactionCoordinator;
use Proxyma\Domain\Execution\AuthorizedWireRequest;
use Proxyma\Domain\Execution\AuthorizedWireSnapshot;
use Proxyma\Domain\Execution\ClaimCandidate;
use Proxyma\Domain\Execution\CommitLatch;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;
use Throwable;

final class DatabaseTransactionCoordinator implements TransactionCoordinator
{
    private const array RETRYABLE_SQL_STATES = ['40P01', '40001', '55P03'];

    private ?CommitLatch $activeLatch = null;

    private ?LockedTransactionContext $activeContext = null;

    /** @var array<int, list<ClaimCandidate>> */
    private array $candidatesByLevel = [];

    /** @var array<string, CommitLatch> */
    private array $committedLatches = [];

    /** @var Closure(): int */
    private readonly Closure $monotonicClock;

    /**
     * @param  (Closure(Closure(): mixed): mixed)|null  $customTransactionDriver
     * @param  (Closure(): int)|null  $customLevelResolver
     * @param  (Closure(): int)|null  $monotonicClock
     */
    public function __construct(
        private readonly string $connectionName = 'pgsql',
        private readonly ?Closure $customTransactionDriver = null,
        private readonly ?Closure $customLevelResolver = null,
        ?Closure $monotonicClock = null,
    ) {
        if ($connectionName === '') {
            throw new InvalidArgumentException('TransactionCoordinator connectionName must not be empty.');
        }

        $this->monotonicClock = $monotonicClock ?? static fn (): int => hrtime(true);
    }

    public function connectionName(): string
    {
        return $this->connectionName;
    }

    public function transactionLevel(): int
    {
        if ($this->customLevelResolver !== null) {
            return ($this->customLevelResolver)();
        }

        return DB::connection($this->connectionName)->transactionLevel();
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

        $initialLevel = $this->transactionLevel();
        if ($initialLevel > 0) {
            if ($this->activeLatch === null || $this->activeContext === null) {
                throw new LogicException('Cannot join an unmanaged SQL transaction that has no CommitLatch.');
            }

            $nestedLevel = $initialLevel + 1;
            $previousContext = $this->activeContext;
            $nestedContext = $previousContext->withNestingLevel($nestedLevel);
            $this->activeContext = $nestedContext;
            $this->candidatesByLevel[$nestedLevel] = [];

            try {
                $result = $this->executeDriverTransaction(static fn (): mixed => $operation($nestedContext));
                $this->activeLatch->onSavepointReleased();

                $nestedCandidates = $this->candidatesAtLevel($nestedLevel);
                if ($nestedCandidates !== []) {
                    $parentLevel = max(1, $nestedLevel - 1);
                    $this->candidatesByLevel[$parentLevel] = [
                        ...$this->candidatesAtLevel($parentLevel),
                        ...$nestedCandidates,
                    ];
                }

                return $result;
            } catch (Throwable $exception) {
                foreach ($this->candidatesAtLevel($nestedLevel) as $candidate) {
                    $candidate->invalidateOnRollback();
                }

                throw $exception;
            } finally {
                unset($this->candidatesByLevel[$nestedLevel]);
                $this->activeContext = $previousContext;
            }
        }

        for ($attempt = 1; ; $attempt++) {
            $monotonicBeforeTxNs = ($this->monotonicClock)();
            $token = Uuid::random()->value;
            $latch = new CommitLatch($this->connectionName, $token, $monotonicBeforeTxNs);
            $context = new LockedTransactionContext($this->connectionName, $token, $monotonicBeforeTxNs, 1);

            $this->activeLatch = $latch;
            $this->activeContext = $context;
            $this->candidatesByLevel = [1 => []];

            try {
                $result = $this->executeDriverTransaction(static fn (): mixed => $operation($context));

                $postCommitLevel = $this->transactionLevel();
                $latch->markOutermostCommitted($postCommitLevel);

                if ($this->candidatesAtLevel(1) !== []) {
                    if (count($this->committedLatches) >= 64) {
                        array_shift($this->committedLatches);
                    }
                    $this->committedLatches[$token] = $latch;
                }

                return $result;
            } catch (Throwable $exception) {
                $latch->markRolledBack();
                foreach ($this->candidatesAtLevel(1) as $candidate) {
                    $candidate->invalidateOnRollback();
                }

                if ($exception instanceof QueryException) {
                    $sqlState = $exception->errorInfo[0] ?? null;
                    if ($attempt < $maxAttempts && is_string($sqlState) && in_array($sqlState, self::RETRYABLE_SQL_STATES, true)) {
                        usleep(random_int(20_000, 200_000));

                        continue;
                    }
                }

                throw $exception;
            } finally {
                $this->activeLatch = null;
                $this->activeContext = null;
                $this->candidatesByLevel = [];
            }
        }
    }

    public function issueCandidate(
        Uuid $permitId,
        Uuid $operationId,
        Uuid $physicalAttemptId,
        ContentHash $wireFingerprint,
        DateTimeImmutable $authorizedAt,
        DateTimeImmutable $expiresAt,
        LockedTransactionContext $context,
    ): ClaimCandidate {
        $context->assertConnection($this->connectionName);

        $level = $this->transactionLevel();
        if ($level < 1 || $this->activeLatch === null || $this->activeContext === null) {
            throw new LogicException('ClaimCandidate can only be issued inside an active TransactionCoordinator transaction.');
        }

        if ($context->outerTransactionToken !== $this->activeLatch->outerTransactionToken) {
            throw new LogicException('LockedTransactionContext outerTransactionToken does not match the active transaction latch.');
        }

        $candidate = $this->activeLatch->issueCandidate(
            permitId: $permitId,
            operationId: $operationId,
            physicalAttemptId: $physicalAttemptId,
            wireFingerprint: $wireFingerprint,
            authorizedAt: $authorizedAt,
            expiresAt: $expiresAt,
            currentTransactionLevel: $level,
        );

        $bucketLevel = $this->activeContext->nestingLevel;
        $this->candidatesByLevel[$bucketLevel][] = $candidate;

        return $candidate;
    }

    public function activate(
        ClaimCandidate $candidate,
        WireRequest $request,
        AuthorizedWireSnapshot $snapshot,
        ?int $nowMonotonicNs = null,
    ): AuthorizedWireRequest {
        if ($candidate->connectionName !== $this->connectionName) {
            throw new LogicException("Cannot activate ClaimCandidate from connection '{$candidate->connectionName}' on '{$this->connectionName}'.");
        }

        $currentLevel = $this->transactionLevel();
        if ($currentLevel !== 0) {
            throw new LogicException('Cannot activate ClaimCandidate before the outermost SQL transaction has committed.');
        }

        $latch = $this->committedLatches[$candidate->outerTransactionToken] ?? null;
        if ($latch === null) {
            throw new LogicException('Cannot activate ClaimCandidate: transaction was not committed or latch was already consumed.');
        }

        $authorized = AuthorizedWireRequest::activate(
            candidate: $candidate,
            latch: $latch,
            currentTransactionLevel: $currentLevel,
            wire: $request,
            snapshot: $snapshot,
            nowMonotonicNs: $nowMonotonicNs ?? ($this->monotonicClock)(),
        );

        unset($this->committedLatches[$candidate->outerTransactionToken]);

        return $authorized;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function executeDriverTransaction(Closure $callback): mixed
    {
        if ($this->customTransactionDriver !== null) {
            return ($this->customTransactionDriver)($callback);
        }

        $connection = DB::connection($this->connectionName);
        $isOuter = $connection->transactionLevel() === 0;

        return $connection->transaction(static function () use ($connection, $isOuter, $callback): mixed {
            if ($isOuter && $connection->getDriverName() === 'pgsql') {
                $connection->statement("SET LOCAL lock_timeout = '2s'");
                $connection->statement("SET LOCAL statement_timeout = '5s'");
            }

            return $callback();
        }, attempts: 1);
    }

    /**
     * @return list<ClaimCandidate>
     */
    private function candidatesAtLevel(int $level): array
    {
        return $this->candidatesByLevel[$level] ?? [];
    }
}
