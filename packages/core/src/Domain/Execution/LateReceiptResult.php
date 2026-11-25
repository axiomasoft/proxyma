<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
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
