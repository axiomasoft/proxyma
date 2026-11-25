<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum LateReceiptOutcome: string
{
    case TransitionedToCompleted = 'transitioned_to_completed';
    case IdempotentDuplicate = 'idempotent_duplicate';
    case ConflictingReceipt = 'conflicting_receipt';
    case RejectedInvalidState = 'rejected_invalid_state';
}
