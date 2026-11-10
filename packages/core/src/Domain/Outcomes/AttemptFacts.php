<?php

declare(strict_types=1);

namespace Proxyma\Domain\Outcomes;

use LogicException;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\IdempotencyClass;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Execution\TransportFailure;
use Proxyma\Domain\Execution\TransportMetrics;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;

/**
 * Неизменяемый технический слепок фактов физической попытки отправки (AttemptFacts).
 * Отделяет фактический результат транспорта и маршрута от доменного решения OutcomePolicy.
 */
final readonly class AttemptFacts
{
    public function __construct(
        public Uuid $operationId,
        public Uuid $physicalAttemptId,
        public int $attemptNumber,
        public int $redirectHop,
        public string $method,
        public SafeUrl $url,
        public CanonicalOrigin $origin,
        public Uuid $targetId,
        public int $policyRevision,
        public Uuid $routeId,
        public RouteKind $routeKind,
        public ?Uuid $endpointId,
        public string $egressKey,
        public ?int $credentialVersion,
        public IdempotencyClass $idempotency,
        public ?int $httpStatus,
        public ?TransportFailure $failure,
        public int $wireBytes,
        public int $decodedBytes,
        public int $curlErrorCode,
        public ?TransportMetrics $metrics,
        public ContentHash $receiptDigest,
        public ?string $retryAfterHeader,
        public ?string $locationHeader,
        public bool $delivered,
        public bool $bodyReplayable,
    ) {}

    public static function fromAttempt(
        WireRequest $request,
        AttemptResult $result,
        Uuid $routeId,
        RouteKind $routeKind,
        ?Uuid $endpointId,
        string $egressKey,
        ?int $credentialVersion,
        int $attemptNumber = 1,
        int $redirectHop = 0,
    ): self {
        $head = $result->head;
        $status = $head?->statusCode;
        $retryAfter = $head?->header('retry-after');
        $location = $head?->header('location');

        $receiptPayload = [
            'operation_id' => $request->operationId->value,
            'physical_attempt_id' => $request->physicalAttemptId->value,
            'wire_fingerprint' => $request->fingerprint()->hex,
            'delivered' => $result->wasDelivered(),
            'status' => $status,
            'failure' => $result->failure?->value,
            'wire_bytes' => $result->wireBytes,
            'decoded_bytes' => $result->decodedBytes,
            'curl_error' => $result->curlErrorCode,
            'headers_digest' => hash('sha256', json_encode($head?->headers, JSON_THROW_ON_ERROR)),
            'body_hash' => $result->response?->body->contentHash()->hex,
            'body_length' => $result->response?->body->byteLength(),
        ];

        $receiptDigest = ContentHash::of('receipt:v1:'.json_encode($receiptPayload, JSON_THROW_ON_ERROR));

        return new self(
            operationId: $request->operationId,
            physicalAttemptId: $request->physicalAttemptId,
            attemptNumber: max(1, $attemptNumber),
            redirectHop: max(0, $redirectHop),
            method: $request->method,
            url: $request->url,
            origin: $request->url->origin,
            targetId: $request->targetId,
            policyRevision: $request->policyRevision,
            routeId: $routeId,
            routeKind: $routeKind,
            endpointId: $endpointId,
            egressKey: $egressKey,
            credentialVersion: $credentialVersion,
            idempotency: $request->idempotency,
            httpStatus: $status,
            failure: $result->failure,
            wireBytes: $result->wireBytes,
            decodedBytes: $result->decodedBytes,
            curlErrorCode: $result->curlErrorCode,
            metrics: $result->metrics,
            receiptDigest: $receiptDigest,
            retryAfterHeader: $retryAfter,
            locationHeader: $location,
            delivered: $result->wasDelivered(),
            bodyReplayable: $request->body === null || $request->body->isReplayable(),
        );
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('AttemptFacts cannot be serialized.');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('AttemptFacts cannot be reconstructed.');
    }
}
