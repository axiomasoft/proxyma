<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Admission;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Domain\Execution\AuthorizedWireSnapshot;
use Proxyma\Domain\Execution\ClaimCandidate;
use Proxyma\Domain\Execution\SealedWire;

/** @internal Local coordinator provenance, not a consumer extension point. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ClaimActivationAuthority
{
    public function assertActivation(ClaimCandidate $candidate, AuthorizedWireSnapshot $snapshot): void;

    public function assertOutsideTransaction(): void;

    public function nowMonotonicNs(): int;

    public function lateRefusal(ClaimCandidate $candidate): void;

    public function preparedWire(ClaimCandidate $candidate): ?SealedWire;
}
