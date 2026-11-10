<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Proxyma\Domain\Execution\CompletionFact;
use Proxyma\Domain\Execution\LockedTransactionContext;

/** Package-owned SQL reducer boundary; not an extension-registry callback. */
interface LockedCompletionReducer extends SqlConnectionParticipant
{
    public function apply(CompletionFact $fact, LockedTransactionContext $transaction): void;
}
