<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use DateTimeImmutable;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Policy\TenantKey;

final readonly class ReadOnlyAttemptObservation
{
    public function __construct(
        public TenantKey $tenantKey,
        public Uuid $operationId,
        public Uuid $physicalAttemptId,
        public Uuid $routeId,
        public RouteKind $routeKind,
        public Uuid $targetId,
        public CanonicalOrigin $origin,
        public string $urlTemplate,
        public ContentHash $wireFingerprint,
        public ?int $httpStatusCode,
        public ?TransportFailure $failure,
        public ?OutcomeClassification $outcome,
        public int $wireBytes,
        public int $decodedBytes,
        public TransportMetrics $metrics,
        public DateTimeImmutable $observedAt,
    ) {}
}
