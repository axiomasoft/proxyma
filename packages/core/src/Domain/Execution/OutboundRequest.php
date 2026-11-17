<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;
use LogicException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class OutboundRequest
{
    private const array METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public string $trafficKey;

    public function __construct(
        public Uuid $operationId,
        public Uuid $targetId,
        public string $method,
        public SafeUrl $url,
        public RequestTimeoutPolicy $timeouts,
        public IdempotencyClass $idempotency = IdempotencyClass::ReadOnly,
        public PreparedHeaders $headers = new PreparedHeaders,
        public ?WireBody $body = null,
        string $trafficKey = 'default',
        public ?int $sendLimit = null,
        public ResponseProfile $responseProfile = new ResponseProfile,
        public ?string $outcomePolicyKey = null,
    ) {
        if (! in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException("Unsupported outbound HTTP method '{$method}'.");
        }

        if (in_array($method, ['GET', 'HEAD'], true) && $body !== null) {
            throw new InvalidArgumentException('Read-only GET/HEAD methods cannot carry a request body.');
        }

        if ($method === 'POST' && $idempotency === IdempotencyClass::ReadOnly && $body === null) {
            throw new InvalidArgumentException('A read-only POST request must pin its body.');
        }

        if (preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $trafficKey) !== 1) {
            throw new InvalidArgumentException('Traffic key must be a valid canonical identifier.');
        }

        $maxPhysicalSends = $this->maxAutomaticAttempts() * ($timeouts->maxRedirects + 1);
        if ($sendLimit !== null && ($sendLimit < 1 || $sendLimit > $maxPhysicalSends)) {
            throw new InvalidArgumentException('Caller sendLimit exceeds the configured timeout and retry policy.');
        }

        $this->trafficKey = $trafficKey;
    }

    public function maxAutomaticAttempts(): int
    {
        return $this->idempotency->allowsAutomaticRetry() ? $this->timeouts->maxAttempts : 1;
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('OutboundRequest cannot be serialized.');
    }
}
