<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Execution;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\ExecutionDeferred;
use Proxyma\Domain\Execution\LateReceiptClaim;
use Proxyma\Domain\Execution\LateReceiptResult;
use Proxyma\Domain\Execution\Uuid;

#[Spi(kind: SpiKind::Call, since: '1.0')]
interface Completion
{
    public function record(
        Uuid $operationId,
        Uuid $physicalAttemptId,
        AttemptResult $result,
        ?ContentHash $receiptDigest = null,
    ): ?ExecutionDeferred;

    public function recordLateReceipt(LateReceiptClaim $receipt): LateReceiptResult;
}
