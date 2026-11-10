<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Proxyma\Domain\Execution\ExecutionBlocked;
use Proxyma\Domain\Execution\LockedOwnerState;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\OwnerRef;
use Proxyma\Domain\Execution\OwnerSnapshot;

/**
 * External owner fencing authority port.
 * Declares connection identity, bounded side-effect-free snapshot reading/validation,
 * and locked prefix verification inside the shared SQL transaction.
 * SQL implementations also implement SqlConnectionParticipant; a connection name alone
 * does not prove that finance and network share a PostgreSQL session.
 */
interface OwnerAuthority
{
    public function connectionName(): string;

    public function snapshot(OwnerRef $owner): ?OwnerSnapshot;

    public function validateSnapshot(OwnerRef $owner, OwnerSnapshot $snapshot): bool;

    public function lockAndValidate(
        OwnerRef $owner,
        OwnerSnapshot $snapshot,
        LockedTransactionContext $transaction,
    ): LockedOwnerState|ExecutionBlocked;
}
