<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use LogicException;
use PDO;
use Proxyma\Domain\Execution\LockedTransactionContext;
use WeakMap;

final class ConnectionScope
{
    /** @var WeakMap<LockedTransactionContext, array{PDO, DatabaseTransactionRecord}> */
    private WeakMap $contexts;

    private readonly TransactionStartClock $clock;

    public function __construct(public readonly Connection $connection, private readonly DatabaseTransactionsManager $transactions)
    {
        $this->contexts = new WeakMap;
        $this->clock = TransactionStartClock::forConnection($connection);
    }

    public function assertSame(Connection $connection): void
    {
        if ($connection !== $this->connection || $connection->getPdo() !== $this->connection->getPdo()) {
            throw new LogicException('Authority participants require the same Connection instance and PDO session.');
        }
    }

    public function current(): DatabaseTransactionRecord
    {
        if ($this->connection->getDriverName() !== 'pgsql' || $this->connection->transactionLevel() < 1
            || ! $this->connection->getPdo()->inTransaction()) {
            throw new LogicException('Locked API requires an active PostgreSQL caller transaction.');
        }
        $record = $this->transactions->getPendingTransactions()->last(fn ($record): bool => $record->connection === $this->connection->getName() && $record->level === $this->connection->transactionLevel());

        return $record ?? throw new LogicException('Caller transaction is not tracked by the configured Laravel transaction manager.');
    }

    public function root(): DatabaseTransactionRecord
    {
        $record = $this->current();
        while ($record->parent !== null) {
            $record = $record->parent;
        }

        return $record;
    }

    public function monotonicBeforeRootNs(): int
    {
        return $this->clock->beforeRoot();
    }

    public function register(LockedTransactionContext $context): void
    {
        $this->contexts[$context] = [$this->connection->getPdo(), $this->current()];
    }

    public function assertActive(LockedTransactionContext $context): void
    {
        $context->assertConnection($this->connection->getName());
        $registered = $this->contexts[$context] ?? null;
        if ($registered === null || $registered[0] !== $this->connection->getPdo() || $registered[1] !== $this->current()) {
            throw new LogicException('Foreign, forged or stale locked transaction context.');
        }
    }

    public function assertOutsideTransaction(): void
    {
        if ($this->connection->transactionLevel() !== 0 || $this->connection->getPdo()->inTransaction()) {
            throw new LogicException('External I/O and capability activation require transactionLevel zero.');
        }
    }
}
