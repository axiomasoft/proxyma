<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\ExecutionDeferred;
use Proxyma\Domain\Execution\LateReceiptClaim;
use Proxyma\Domain\Execution\LateReceiptResult;
use Proxyma\Domain\Execution\Uuid;

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
