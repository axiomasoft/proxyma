<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use LogicException;
use Proxyma\Domain\Catalog\Route;

/** Prepared outside SQL; this immutable intent grants no authority to send. */
final readonly class SealedWire
{
    public function __construct(public WireRequest $request, public Route $route, public ResolvedUrl $destination,
        public TlsTrustConfiguration $tls, public ?ResolvedSecret $credential = null) {}

    public function __serialize(): array
    {
        throw new LogicException('SealedWire cannot be serialized.');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('SealedWire cannot be reconstructed.');
    }

    public function __debugInfo(): array
    {
        return ['operation_id' => $this->request->operationId->value, 'fingerprint' => $this->request->fingerprint()->hex];
    }
}
