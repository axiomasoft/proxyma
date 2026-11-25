<?php

declare(strict_types=1);

namespace Proxyma\Application\Execution;

use LogicException;
use Packman\Attributes\Api;
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
use SensitiveParameter;

#[Api(since: '1.0')]
final readonly class WireSealer
{
    public function __construct(private ConnectionScope $scope, #[SensitiveParameter] private string $digestKey)
    {
        if (strlen($digestKey) < 32) {
            throw new AdmissionRejected('wire_digest_key_required');
        }
    }

    public function seal(WireRequest $request, Route $route, ResolvedUrl $destination, TlsTrustConfiguration $tls,
        ?ResolvedSecret $credential = null, ?TlsTrustConfiguration $proxyTls = null, ?ProxyDestination $proxy = null): SealedWire
    {
        $this->scope->assertOutsideTransaction();
        $this->assertProvenance($request, $route, $destination, $credential, $proxy, 'wire_preparation_conflict', 'wire_proxy_conflict');
        $headers = new PreparedHeaders($request->headers->reveal(), $this->digestKey);
        $digest = $this->digest($request, $headers, $route, $destination, $tls, $credential, $proxyTls, $proxy);
        $wire = new WireRequest($request->operationId, $request->physicalAttemptId, $request->targetId, $request->policyRevision,
            $request->method, $request->url, $request->timeouts, $request->deadlineAt, $headers, $request->body,
            $request->idempotency, $request->purpose, $digest, $request->trafficClass, $request->responseProfile);

        return new SealedWire($wire, $route, $destination, $tls, $credential, $proxyTls, $proxy);
    }

    /** Pure validation: all DNS, secret and body preparation has already finished. */
    public function verify(SealedWire $wire): void
    {
        $this->assertProvenance($wire->request, $wire->route, $wire->destination, $wire->credential, $wire->proxy, 'wire_seal_conflict', 'wire_seal_conflict');

        $expectedHeaders = new PreparedHeaders($wire->request->headers->reveal(), $this->digestKey);
        $expectedDigest = $this->digest($wire->request, $wire->request->headers, $wire->route, $wire->destination, $wire->tls, $wire->credential, $wire->proxyTls, $wire->proxy);
        $sealDigest = $wire->request->sealDigest;

        if ($sealDigest === null
            || ! hash_equals($expectedDigest->hex, $sealDigest->hex)
            || ! hash_equals($expectedHeaders->digest->hex, $wire->request->headers->digest->hex)) {
            throw new AdmissionRejected('wire_seal_conflict');
        }
    }

    private function assertProvenance(
        WireRequest $request,
        Route $route,
        ResolvedUrl $destination,
        ?ResolvedSecret $credential,
        ?ProxyDestination $proxy,
        string $preparationReason,
        string $proxyReason,
    ): void {
        if (! $destination->isPolicyVerified()
            || $request->url->hash()->hex !== $destination->url->hash()->hex
            || $destination->targetId === null
            || ! $destination->targetId->equals($request->targetId)
            || ($credential !== null && ($route->endpointId === null || $credential->reference->version !== $route->credentialVersion))) {
            throw new AdmissionRejected($preparationReason);
        }
        if ($proxy !== null && ($route->endpointId === null
            || ! $proxy->endpoint->id->equals($route->endpointId)
            || ! $proxy->endpoint->tenantKey->equals($route->tenantKey)
            || $proxy->endpoint->revision !== $route->endpointRevision
            || $proxy->endpoint->credentialVersion() !== $route->credentialVersion
            || $proxy->endpoint->credential?->reference !== $credential?->reference->reference)) {
            throw new AdmissionRejected($proxyReason);
        }
    }

    private function digest(
        WireRequest $request,
        PreparedHeaders $headers,
        Route $route,
        ResolvedUrl $destination,
        TlsTrustConfiguration $tls,
        ?ResolvedSecret $credential,
        ?TlsTrustConfiguration $proxyTls,
        ?ProxyDestination $proxy,
    ): ContentHash {
        return new ContentHash(hash_hmac('sha256', json_encode([
            'request' => [
                $request->operationId->value,
                $request->physicalAttemptId->value,
                $request->targetId->value,
                $request->policyRevision,
                $request->method,
                $request->url->hash()->hex,
                $headers->digest->hex,
                $request->body?->hash->hex,
                $request->body->length ?? 0,
                $request->body?->replayable,
                [
                    $request->timeouts->connectTimeoutMs,
                    $request->timeouts->readTimeoutMs,
                    $request->timeouts->totalTimeoutMs,
                    $request->timeouts->cleanupMarginMs,
                    $request->timeouts->maxAttempts,
                    $request->timeouts->maxDistinctRoutes,
                    $request->timeouts->maxRedirects,
                    $request->timeouts->retryDelaysMs,
                ],
                $request->idempotency->value,
                $request->purpose->value,
                $request->deadlineAt->format('Y-m-d\TH:i:s.uP'),
                $request->trafficClass,
                [
                    $request->responseProfile->kind,
                    $request->responseProfile->maxWireBytes,
                    $request->responseProfile->maxEntityBytes,
                    $request->responseProfile->maxTextBytes,
                    $request->responseProfile->charsetDefault,
                ],
            ],
            $route->tenantKey->value, $route->id->value, $route->revision, $route->kind->value, $route->endpointId?->value,
            $route->endpointRevision, $route->credentialVersion, $route->egress->key,
            $destination->url->hash()->hex, $destination->address, $destination->addresses,
            $destination->targetId?->value, $destination->targetRevision, $destination->destinationPolicyKey, $destination->originalHost, $destination->port,
            $destination->targetOrigin?->value(),
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
        throw new LogicException('WireSealer contains a private digest key.');
    }
}
