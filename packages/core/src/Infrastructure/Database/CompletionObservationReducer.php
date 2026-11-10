<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use Illuminate\Database\Connection;
use Proxyma\Contracts\LockedCompletionReducer;
use Proxyma\Domain\Execution\CompletionFact;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\Uuid;

final readonly class CompletionObservationReducer implements LockedCompletionReducer
{
    public function __construct(private ConnectionScope $scope) {}

    public function connection(): Connection
    {
        return $this->scope->connection;
    }

    public function apply(CompletionFact $fact, LockedTransactionContext $transaction): void
    {
        $this->scope->assertActive($transaction);
        $t = PackageSchema::tables();
        $this->scope->connection->table($t['observation_applications'])->insert(['id' => Uuid::random()->value, 'tenant_key' => $fact->tenant->value,
            'observation_id' => $fact->observationId->value, 'application_key' => 'completion_fact', 'health_epoch' => 1]);
    }
}
