<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Execution;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Contracts\Admission\SqlConnectionParticipant;
use Proxyma\Domain\Execution\CompletionFact;
use Proxyma\Domain\Execution\LockedTransactionContext;

/** Package-owned SQL reducer boundary; not an extension-registry callback. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface LockedCompletionReducer extends SqlConnectionParticipant
{
    public function apply(CompletionFact $fact, LockedTransactionContext $transaction): void;
}
