<?php

declare(strict_types=1);

namespace Proxyma\Application\Execution;

use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\PreparedHeaders;
use Proxyma\Domain\Execution\ResolvedSecret;
use Proxyma\Domain\Execution\ResolvedUrl;
use Proxyma\Domain\Execution\SealedWire;
use Proxyma\Domain\Execution\TlsTrustConfiguration;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Infrastructure\Database\ConnectionScope;

final readonly class WireSealer
{
    public function __construct(private ConnectionScope $scope, private string $digestKey)
    {
        if (strlen($digestKey) < 32) {
            throw new AdmissionRejected('wire_digest_key_required');
        }
    }

    public function seal(WireRequest $request, Route $route, ResolvedUrl $destination, TlsTrustConfiguration $tls,
        ?ResolvedSecret $credential = null): SealedWire
    {
        $this->scope->assertOutsideTransaction();
        if (! $destination->isPolicyVerified() || $request->url->hash()->hex !== $destination->url->hash()->hex
            || ($destination->targetId !== null && ! $destination->targetId->equals($request->targetId))
            || ($credential !== null && ($route->endpointId === null || $credential->reference->version !== $route->credentialVersion))) {
            throw new AdmissionRejected('wire_preparation_conflict');
        }
        $headers = new PreparedHeaders($request->headers->reveal(), $this->digestKey);
        $digest = $this->digest($route, $destination, $tls, $credential);
        $wire = new WireRequest($request->operationId, $request->physicalAttemptId, $request->targetId, $request->policyRevision,
            $request->method, $request->url, $request->timeouts, $request->deadlineAt, $headers, $request->body,
            $request->idempotency, $request->purpose, $digest, $request->trafficClass);

        return new SealedWire($wire, $route, $destination, $tls, $credential);
    }

    /** Pure validation: all DNS, secret and body preparation has already finished. */
    public function verify(SealedWire $wire): void
    {
        if (! $wire->destination->isPolicyVerified() || $wire->request->sealDigest?->hex !== $this->digest($wire->route, $wire->destination, $wire->tls, $wire->credential)->hex
            || $wire->request->url->hash()->hex !== $wire->destination->url->hash()->hex
            || $wire->request->headers->digest->hex !== (new PreparedHeaders($wire->request->headers->reveal(), $this->digestKey))->digest->hex) {
            throw new AdmissionRejected('wire_seal_conflict');
        }
    }

    private function digest(Route $route, ResolvedUrl $destination, TlsTrustConfiguration $tls, ?ResolvedSecret $credential): ContentHash
    {
        return new ContentHash(hash_hmac('sha256', json_encode([
            $route->tenantKey->value, $route->id->value, $route->revision, $route->kind->value, $route->endpointId?->value,
            $route->endpointRevision, $route->credentialVersion, $route->egress->key,
            $destination->url->hash()->hex, $destination->address, $destination->addresses,
            $destination->targetId?->value, $destination->targetRevision, $destination->destinationPolicyKey, $destination->originalHost, $destination->port,
            $tls->verifyPeer, $tls->verifyHost, $tls->caBundlePath, $tls->pinnedCertificateSha256?->hex,
            $credential?->reference->reference, $credential?->reference->version, $credential?->username, $credential?->revealSecret(),
        ], JSON_THROW_ON_ERROR), $this->digestKey));
    }

    public function __debugInfo(): array
    {
        return [];
    }

    public function __serialize(): array
    {
        throw new \LogicException('WireSealer contains a private digest key.');
    }
}
