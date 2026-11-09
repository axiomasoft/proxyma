<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;
use LogicException;
use Proxyma\Domain\Catalog\CanonicalOrigin;

final readonly class SafeUrl
{
    public CanonicalOrigin $origin;

    public function __construct(private string $value)
    {
        if ($value === '' || strlen($value) > 8192 || preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
            throw new InvalidArgumentException('URL must be bounded and free of whitespace or control bytes.');
        }

        $parts = parse_url($value);
        if (! is_array($parts) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('URL must be absolute and must not carry a fragment.');
        }

        $this->origin = CanonicalOrigin::fromUrl($value);
    }

    public function reveal(): string
    {
        return $this->value;
    }

    public function template(): string
    {
        $path = parse_url($this->value, PHP_URL_PATH);

        return $this->origin->value().(is_string($path) && $path !== '' ? $path : '/');
    }

    public function hash(): ContentHash
    {
        return ContentHash::of('url:v1:'.$this->value);
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('SafeUrl cannot be serialized because it may contain signed query parameters.');
    }

    /**
     * @return array{template: string, hash: string}
     */
    public function __debugInfo(): array
    {
        return [
            'template' => $this->template(),
            'hash' => $this->hash()->hex,
        ];
    }
}
