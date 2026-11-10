<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use LogicException;
use Proxyma\Domain\Catalog\Route;

/** Prepared outside SQL; this immutable intent grants no authority to send. */
final readonly class SealedWire
{
    public function __construct(public WireRequest $request, public Route $route, public ResolvedUrl $destination,
        public TlsTrustConfiguration $tls, public ?ResolvedSecret $credential = null, public ?TlsTrustConfiguration $proxyTls = null) {}

    public function connectionReuseKey(): string
    {
        return hash('sha256', json_encode([$this->route->tenantKey->value, $this->route->id->value,
            $this->route->revision, $this->route->accessGrant?->id->value, $this->route->endpointId?->value, $this->route->endpointRevision,
            $this->route->credentialVersion, $this->credential?->reference, $this->destination->url->origin->value(),
            $this->destination->address, $this->tls, $this->proxyTls], JSON_THROW_ON_ERROR));
    }

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
