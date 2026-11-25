<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Transport-level HTTP response descriptor.
 * Represents the physical fact that an HTTP response was received (including 4xx/5xx);
 * business meaning and retry/health classification belong to OutcomePolicy.
 */
#[Api(since: '1.0')]
final readonly class ResponseDescriptor
{
    public HttpResponseHead $head;

    /**
     * @param  array<string, list<string>>  $headers
     */
    public function __construct(
        public int $statusCode,
        array $headers,
        public ResponseBody $body,
        public int $wireBytes,
        public int $decodedBytes,
    ) {
        if ($wireBytes < 0 || $decodedBytes < 0) {
            throw new InvalidArgumentException('Response byte counters must not be negative.');
        }

        $this->head = new HttpResponseHead($statusCode, $headers);
    }

    public function header(string $name): ?string
    {
        return $this->head->header($name);
    }
}
