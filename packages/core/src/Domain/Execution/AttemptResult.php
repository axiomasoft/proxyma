<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class AttemptResult
{
    public ?HttpResponseHead $head;

    public function __construct(
        public ?ResponseDescriptor $response,
        public ?TransportFailure $failure,
        public int $wireBytes,
        public int $decodedBytes,
        public int $curlErrorCode = 0,
        ?HttpResponseHead $partialHead = null,
        public ?TransportMetrics $metrics = null,
    ) {
        if (($response === null) === ($failure === null)) {
            throw new InvalidArgumentException('AttemptResult must have either a delivered ResponseDescriptor or a TransportFailure, never both or neither.');
        }

        if ($wireBytes < 0 || $decodedBytes < 0) {
            throw new InvalidArgumentException('Attempt byte counts must be non-negative.');
        }

        $this->head = $response->head ?? $partialHead;
    }

    public static function delivered(ResponseDescriptor $response, ?TransportMetrics $metrics = null): self
    {
        return new self(
            response: $response,
            failure: null,
            wireBytes: $response->wireBytes,
            decodedBytes: $response->decodedBytes,
            curlErrorCode: 0,
            partialHead: $response->head,
            metrics: $metrics,
        );
    }

    public static function failed(
        TransportFailure $failure,
        int $wireBytes = 0,
        int $decodedBytes = 0,
        int $curlErrorCode = 0,
        ?HttpResponseHead $partialHead = null,
        ?TransportMetrics $metrics = null,
    ): self {
        return new self(
            response: null,
            failure: $failure,
            wireBytes: $wireBytes,
            decodedBytes: $decodedBytes,
            curlErrorCode: $curlErrorCode,
            partialHead: $partialHead,
            metrics: $metrics,
        );
    }

    public function wasDelivered(): bool
    {
        return $this->response !== null;
    }
}
