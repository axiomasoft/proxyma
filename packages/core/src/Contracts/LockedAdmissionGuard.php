<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Proxyma\Domain\Execution\AdmissionLockTier;
use Proxyma\Domain\Execution\ClaimDenied;
use Proxyma\Domain\Execution\LockedOwnerState;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\WireRequest;

/**
 * Deny-only admission guard at a declared lock tier.
 * Cannot mint capabilities or bypass mandatory core checks.
 */
interface LockedAdmissionGuard
{
    public function tier(): AdmissionLockTier;

    public function evaluateLocked(
        WireRequest $request,
        LockedOwnerState $owner,
        LockedTransactionContext $transaction,
    ): ?ClaimDenied;
}
