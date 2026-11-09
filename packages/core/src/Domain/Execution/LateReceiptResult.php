<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

final readonly class LateReceiptResult
{
    public function __construct(
        public LateReceiptOutcome $outcome,
        public PermitState $permitState,
        public bool $receiptPersisted,
        public bool $healthReducerAllowed,
        public bool $quotaConsumedUnchanged = true,
        public bool $inflightRemainsReleased = true,
    ) {}
}
