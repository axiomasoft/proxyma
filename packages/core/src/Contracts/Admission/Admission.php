<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Admission;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Domain\Execution\ClaimCandidate;
use Proxyma\Domain\Execution\ClaimDenied;
use Proxyma\Domain\Execution\DemandTicket;
use Proxyma\Domain\Execution\ExecutionBlocked;
use Proxyma\Domain\Execution\ExecutionContext;
use Proxyma\Domain\Execution\ExecutionDeferred;
use Proxyma\Domain\Execution\LockedOwnerState;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\PermitWait;
use Proxyma\Domain\Execution\RouteSelection;
use Proxyma\Domain\Execution\ScheduledPermit;
use Proxyma\Domain\Execution\WireRequest;

#[Spi(kind: SpiKind::Call, since: '1.0')]
interface Admission
{
    public function reserve(
        WireRequest $request,
        ExecutionContext $context,
        RouteSelection $route,
    ): ScheduledPermit|PermitWait|ExecutionDeferred|ExecutionBlocked;

    public function claimSendLocked(
        ScheduledPermit $permit,
        WireRequest $request,
        LockedOwnerState $ownerState,
        LockedTransactionContext $transaction,
    ): ClaimCandidate|PermitWait|ClaimDenied|ExecutionDeferred|ExecutionBlocked;

    public function abandon(DemandTicket $ticket, ExecutionContext $context): void;
}
