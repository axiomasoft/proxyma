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
    public function __construct(#[\SensitiveParameter] array $headers = [], #[\SensitiveParameter] ?string $digestKey = null)
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $lower = strtolower($name);
            if (
                preg_match('/^[a-z0-9!#$%&\'*+.^_`|~-]+$/D', $lower) !== 1
                || preg_match('//u', $value) !== 1
                || preg_match('/[\r\n\x00]/', $value) === 1
                || in_array($lower, self::FORBIDDEN_TRANSPORT_HEADERS, true)
            ) {
                throw new InvalidArgumentException('Invalid or transport-owned request header.');
            }

            if (array_key_exists($lower, $normalized)) {
                throw new InvalidArgumentException('Duplicate normalized request header.');
            }
            $normalized[$lower] = $value;
        }

        ksort($normalized, SORT_STRING);
        $this->headers = $normalized;
        $canonical = 'headers:v1:'.json_encode($normalized, JSON_THROW_ON_ERROR);
        $this->digest = $digestKey === null ? ContentHash::of($canonical) : new ContentHash(hash_hmac('sha256', $canonical, $digestKey));
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

    public function acrossOrigin(bool $preserveContentType = false): self
    {
        $allowed = $preserveContentType ? ['accept', 'user-agent', 'content-type'] : ['accept', 'user-agent'];

        return new self(array_intersect_key($this->headers, array_flip($allowed)));
    }

    public function withoutContentHeaders(): self
    {
        return new self(array_diff_key($this->headers, array_flip([
            'content-type',
            'content-encoding',
            'content-language',
            'content-location',
            'digest',
        ])));
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('PreparedHeaders cannot be serialized because they may contain sensitive credentials.');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('PreparedHeaders cannot be reconstructed.');
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
