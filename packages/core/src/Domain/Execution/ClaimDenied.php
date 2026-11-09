<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

final readonly class ClaimDenied
{
    /** @var array<string, int|float|string|bool|null> */
    public array $safeDetails;

    /**
     * @param  array<string, int|float|string|bool|null>  $safeDetails
     */
    public function __construct(
        public ClaimDenialReason $reason,
        array $safeDetails = [],
    ) {
        $this->safeDetails = ExecutionBlocked::validateSafeDetails($safeDetails);
    }

    public function isDuplicateClaim(): bool
    {
        return $this->reason === ClaimDenialReason::AlreadyClaimed;
    }
}
