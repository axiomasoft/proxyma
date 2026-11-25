<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class HttpResponseHead
{
    /** @var array<string, list<string>> */
    public array $headers;

    /**
     * @param  array<string, list<string>>  $headers
     */
    public function __construct(
        public int $statusCode,
        array $headers = [],
    ) {
        if ($statusCode < 100 || $statusCode > 599) {
            throw new InvalidArgumentException('HTTP status code must be between 100 and 599.');
        }

        $normalized = [];
        foreach ($headers as $name => $values) {
            $lower = strtolower($name);
            if (preg_match('/^[a-z0-9!#$%&\'*+.^_`|~-]+$/D', $lower) !== 1) {
                throw new InvalidArgumentException("Invalid response header name '{$name}'.");
            }

            $list = [];
            foreach ($values as $value) {
                if (preg_match('/[\r\n\x00]/', $value) === 1) {
                    throw new InvalidArgumentException('Invalid response header value.');
                }
                $list[] = $value;
            }

            $normalized[$lower] = [...($normalized[$lower] ?? []), ...$list];
        }

        ksort($normalized, SORT_STRING);
        $this->headers = $normalized;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }
}
