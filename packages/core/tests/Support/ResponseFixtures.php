<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Proxyma\Infrastructure\Database\ConnectionScope;

function responseScope(bool $transaction = false): ConnectionScope
{
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('inTransaction')->andReturn($transaction);
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('beforeStartingTransaction');
    $connection->shouldReceive('transactionLevel')->andReturn($transaction ? 1 : 0);
    $connection->shouldReceive('getPdo')->andReturn($pdo);

    return new ConnectionScope($connection, new DatabaseTransactionsManager);
}
