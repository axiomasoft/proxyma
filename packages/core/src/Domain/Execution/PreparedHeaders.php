<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;
use LogicException;

/**
 * Runtime-only prepared headers with deterministic SHA-256 header digest.
 * Secrets in headers are never serialized or exposed in debug output.
 */
final readonly class PreparedHeaders
{
    private const array FORBIDDEN_TRANSPORT_HEADERS = [
        'host',
        'connection',
        'content-length',
        'transfer-encoding',
        'proxy-authorization',
        'accept-encoding',
    ];

    /** @var array<string, string> */
    private array $headers;

    public ContentHash $digest;

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(array $headers = [])
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $lower = strtolower($name);
            if (
                preg_match('/^[a-z0-9!#$%&\'*+.^_`|~-]+$/D', $lower) !== 1
                || preg_match('/[\r\n\x00]/', $value) === 1
                || in_array($lower, self::FORBIDDEN_TRANSPORT_HEADERS, true)
            ) {
                throw new InvalidArgumentException("Invalid or transport-owned request header '{$name}'.");
            }

            $normalized[$lower] = $value;
        }

        ksort($normalized, SORT_STRING);
        $this->headers = $normalized;
        $this->digest = ContentHash::of('headers:v1:'.json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    /**
     * Only for physical transport binding; never log raw values.
     *
     * @return array<string, string>
     */
    public function reveal(): array
    {
        return $this->headers;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->headers);
    }

    public function acrossOrigin(): self
    {
        return new self(array_intersect_key($this->headers, array_flip(['accept', 'user-agent'])));
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('PreparedHeaders cannot be serialized because they may contain sensitive credentials.');
    }

    /**
     * @return array{names: list<string>, digest: string}
     */
    public function __debugInfo(): array
    {
        return [
            'names' => $this->names(),
            'digest' => $this->digest->hex,
        ];
    }
}
