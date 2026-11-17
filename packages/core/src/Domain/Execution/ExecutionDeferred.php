<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ExecutionDeferred implements ExecutionResult
{
    /**
     * @param  list<Uuid>  $attemptIds
     */
    public function __construct(public DeferralReason $reason, public RetryAt $retryAt, public array $attemptIds = [], public ?ResponseDescriptor $lastDeliveredResponse = null) {}
}
