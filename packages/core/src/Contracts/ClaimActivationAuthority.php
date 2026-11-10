<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Proxyma\Domain\Execution\AuthorizedWireSnapshot;
use Proxyma\Domain\Execution\ClaimCandidate;
use Proxyma\Domain\Execution\SealedWire;

/** @internal Local coordinator provenance, not a consumer extension point. */
interface ClaimActivationAuthority
{
    public function assertActivation(ClaimCandidate $candidate, AuthorizedWireSnapshot $snapshot): void;

    public function assertOutsideTransaction(): void;

    public function nowMonotonicNs(): int;

    public function lateRefusal(ClaimCandidate $candidate): void;

    public function preparedWire(ClaimCandidate $candidate): ?SealedWire;
}
