<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

final readonly class ExecutionDeferred implements ExecutionResult
{
    /** @var list<Uuid> */
    public array $attemptIds;

    /**
     * @param  list<Uuid>  $attemptIds
     */
    public function __construct(
        public DeferralReason $reason,
        public RetryAt $retryAt,
        array $attemptIds = [],
        public ?ResponseDescriptor $lastDeliveredResponse = null,
    ) {
        $this->attemptIds = $attemptIds;
    }
}
