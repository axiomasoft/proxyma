<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;

final readonly class WireRequest
{
    private const array METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public DateTimeImmutable $deadlineAt;

    public ContentHash $wireFingerprint;

    public function __construct(
        public Uuid $operationId,
        public Uuid $physicalAttemptId,
        public Uuid $targetId,
        public int $policyRevision,
        public string $method,
        public SafeUrl $url,
        public RequestTimeoutPolicy $timeouts,
        DateTimeImmutable $deadlineAt,
        public PreparedHeaders $headers = new PreparedHeaders,
        public ?WireBody $body = null,
        public IdempotencyClass $idempotency = IdempotencyClass::ReadOnly,
        public DemandPurpose $purpose = DemandPurpose::Ordinary,
        public ?ContentHash $sealDigest = null,
        public string $trafficClass = 'default',
    ) {
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $trafficClass) !== 1) {
            throw new InvalidArgumentException('Invalid traffic class.');
        }
        if (! in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException("Unsupported wire HTTP method '{$method}'.");
        }

        if (in_array($method, ['GET', 'HEAD'], true) && $body !== null) {
            throw new InvalidArgumentException('GET and HEAD wire requests cannot carry a body.');
        }

        if ($method === 'POST' && $idempotency === IdempotencyClass::ReadOnly && $body === null) {
            throw new InvalidArgumentException('A read-only POST wire request must pin its body.');
        }

        if ($policyRevision < 1) {
            throw new InvalidArgumentException('Policy revision on WireRequest must be at least 1.');
        }

        $this->deadlineAt = $deadlineAt->setTimezone(new DateTimeZone('UTC'));
        $this->wireFingerprint = ContentHash::of('wire:v1:'.json_encode([
            'operation_id' => $operationId->value,
            'physical_attempt_id' => $physicalAttemptId->value,
            'target_id' => $targetId->value,
            'policy_revision' => $policyRevision,
            'method' => $method,
            'url_hash' => $url->hash()->hex,
            'headers_digest' => $headers->digest->hex,
            'body_hash' => $body?->hash->hex,
            'body_length' => $body->length ?? 0,
            'timeouts' => [
                $timeouts->connectTimeoutMs,
                $timeouts->readTimeoutMs,
                $timeouts->totalTimeoutMs,
                $timeouts->cleanupMarginMs,
                $timeouts->maxAttempts,
                $timeouts->maxDistinctRoutes,
                $timeouts->maxRedirects,
                $timeouts->retryDelaysMs,
            ],
            'idempotency' => $idempotency->value,
            'purpose' => $purpose->value,
            'deadline' => $this->deadlineAt->format('Y-m-d\TH:i:s.uP'),
            'seal' => $sealDigest?->hex,
            'traffic_class' => $trafficClass,
        ], JSON_THROW_ON_ERROR));
    }

    public function fingerprint(): ContentHash
    {
        return $this->wireFingerprint;
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('WireRequest cannot be serialized.');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('WireRequest cannot be reconstructed.');
    }
}
