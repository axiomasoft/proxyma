<?php

declare(strict_types=1);

namespace Proxyma\Application\Execution;

use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\PreparedHeaders;
use Proxyma\Domain\Execution\ProxyDestination;
use Proxyma\Domain\Execution\ResolvedSecret;
use Proxyma\Domain\Execution\ResolvedUrl;
use Proxyma\Domain\Execution\SealedWire;
use Proxyma\Domain\Execution\TlsTrustConfiguration;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Infrastructure\Database\ConnectionScope;

final readonly class WireSealer
{
    public function __construct(private ConnectionScope $scope, #[\SensitiveParameter] private string $digestKey)
    {
        if (strlen($digestKey) < 32) {
            throw new AdmissionRejected('wire_digest_key_required');
        }
    }

    public function seal(WireRequest $request, Route $route, ResolvedUrl $destination, TlsTrustConfiguration $tls,
        ?ResolvedSecret $credential = null, ?TlsTrustConfiguration $proxyTls = null, ?ProxyDestination $proxy = null): SealedWire
    {
        $this->scope->assertOutsideTransaction();
        if (! $destination->isPolicyVerified() || $request->url->hash()->hex !== $destination->url->hash()->hex
            || ($destination->targetId !== null && ! $destination->targetId->equals($request->targetId))
            || ($credential !== null && ($route->endpointId === null || $credential->reference->version !== $route->credentialVersion))) {
            throw new AdmissionRejected('wire_preparation_conflict');
        }
        if ($proxy !== null && ($route->endpointId === null || ! $proxy->endpoint->id->equals($route->endpointId)
            || ! $proxy->endpoint->tenantKey->equals($route->tenantKey)
            || $proxy->endpoint->revision !== $route->endpointRevision
            || $proxy->endpoint->credentialVersion() !== $route->credentialVersion
            || $proxy->endpoint->credential?->reference !== $credential?->reference->reference)) {
            throw new AdmissionRejected('wire_proxy_conflict');
        }
        $headers = new PreparedHeaders($request->headers->reveal(), $this->digestKey);
        $digest = $this->digest($route, $destination, $tls, $credential, $proxyTls, $proxy);
        $wire = new WireRequest($request->operationId, $request->physicalAttemptId, $request->targetId, $request->policyRevision,
            $request->method, $request->url, $request->timeouts, $request->deadlineAt, $headers, $request->body,
            $request->idempotency, $request->purpose, $digest, $request->trafficClass, $request->responseProfile);

        return new SealedWire($wire, $route, $destination, $tls, $credential, $proxyTls, $proxy);
    }

    /** Pure validation: all DNS, secret and body preparation has already finished. */
    public function verify(SealedWire $wire): void
    {
        if (! $wire->destination->isPolicyVerified() || $wire->request->sealDigest?->hex !== $this->digest($wire->route, $wire->destination, $wire->tls, $wire->credential, $wire->proxyTls, $wire->proxy)->hex
            || $wire->request->url->hash()->hex !== $wire->destination->url->hash()->hex
            || $wire->request->headers->digest->hex !== (new PreparedHeaders($wire->request->headers->reveal(), $this->digestKey))->digest->hex) {
            throw new AdmissionRejected('wire_seal_conflict');
        }
    }

    private function digest(Route $route, ResolvedUrl $destination, TlsTrustConfiguration $tls, ?ResolvedSecret $credential, ?TlsTrustConfiguration $proxyTls, ?ProxyDestination $proxy): ContentHash
    {
        return new ContentHash(hash_hmac('sha256', json_encode([
            $route->tenantKey->value, $route->id->value, $route->revision, $route->kind->value, $route->endpointId?->value,
            $route->endpointRevision, $route->credentialVersion, $route->egress->key,
            $destination->url->hash()->hex, $destination->address, $destination->addresses,
            $destination->targetId?->value, $destination->targetRevision, $destination->destinationPolicyKey, $destination->originalHost, $destination->port,
            $tls->verifyPeer, $tls->verifyHost, $tls->caBundlePath, $tls->pinnedCertificateSha256?->hex, $tls->caBundleSha256?->hex,
            $proxyTls,
            $proxy,
            $credential?->reference->reference, $credential?->reference->version,
            $credential === null ? null : hash_hmac('sha256', ($credential->username ?? '')."\0".$credential->revealSecret(), $this->digestKey),
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
