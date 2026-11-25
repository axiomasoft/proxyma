<?php

declare(strict_types=1);

namespace Proxyma\Laravel;

use Closure;
use DateTimeImmutable;
use LogicException;
use Packman\Attributes\Api;
use Proxyma\Application\Admission\TransactionCoordinator as SqlCoordinator;
use Proxyma\Contracts\Admission\TransactionCoordinator;
use Proxyma\Domain\Execution\AuthorizedWireRequest;
use Proxyma\Domain\Execution\AuthorizedWireSnapshot;
use Proxyma\Domain\Execution\ClaimCandidate;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;

/** Compatibility name; production has one SQL coordinator and no synthetic transaction driver. */
#[Api(since: '1.0')]
final readonly class DatabaseTransactionCoordinator implements TransactionCoordinator
{
    private SqlCoordinator $coordinator;

    public function __construct(string $connectionName = 'pgsql')
    {
        $this->coordinator = app(SqlCoordinator::class);
        if ($this->coordinator->connectionName() !== $connectionName) {
            throw new LogicException('Use the configured authority connection.');
        }
    }

    public function connectionName(): string
    {
        return $this->coordinator->connectionName();
    }

    public function transactionLevel(): int
    {
        return $this->coordinator->transactionLevel();
    }

    public function inTransaction(): bool
    {
        return $this->coordinator->inTransaction();
    }

    public function run(Closure $operation, ?int $maxAttempts = null): mixed
    {
        return $this->coordinator->run($operation, $maxAttempts);
    }

    public function issueCandidate(Uuid $permitId, Uuid $operationId, Uuid $physicalAttemptId, ContentHash $wireFingerprint,
        DateTimeImmutable $authorizedAt, DateTimeImmutable $expiresAt, LockedTransactionContext $context): ClaimCandidate
    {
        return $this->coordinator->issueCandidate($permitId, $operationId, $physicalAttemptId, $wireFingerprint, $authorizedAt, $expiresAt, $context);
    }

    public function activate(ClaimCandidate $candidate, WireRequest $request, AuthorizedWireSnapshot $snapshot, ?int $nowMonotonicNs = null): AuthorizedWireRequest
    {
        return $this->coordinator->activate($candidate, $request, $snapshot, $nowMonotonicNs);
    }
}
