<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use Illuminate\Database\Connection;
use Packman\Attributes\Api;
use Proxyma\Contracts\Execution\LockedCompletionReducer;
use Proxyma\Domain\Execution\CompletionFact;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Health\HealthReducer;

#[Api(since: '1.0')]
final readonly class CompletionObservationReducer implements LockedCompletionReducer
{
    public function __construct(private ConnectionScope $scope, private HealthReducer $reducer = new HealthReducer) {}

    public function connection(): Connection
    {
        return $this->scope->connection;
    }

    public function apply(CompletionFact $fact, LockedTransactionContext $transaction): void
    {
        new HealthRepository($this->scope, $this->reducer)->apply($fact, $transaction);
    }
}
