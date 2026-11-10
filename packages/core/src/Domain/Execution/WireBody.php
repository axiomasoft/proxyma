<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;
use LogicException;

/**
 * Immutable request body handle with verified SHA-256 digest and byte length.
 */
final readonly class WireBody
{
    public ContentHash $hash;

    public int $length;

    private function __construct(
        private string $bytes,
        ContentHash $hash,
        int $length,
    ) {
        $this->hash = $hash;
        $this->length = $length;
    }

    public static function fromString(string $bytes): self
    {
        return new self($bytes, ContentHash::of($bytes), strlen($bytes));
    }

    public static function fromVerified(string $bytes, ContentHash $expectedHash, ?int $expectedLength = null): self
    {
        $actualLength = strlen($bytes);
        if ($expectedLength !== null && $actualLength !== $expectedLength) {
            throw new InvalidArgumentException('Request body byte length does not match expected length.');
        }

        $actualHash = ContentHash::of($bytes);
        if (! $actualHash->equals($expectedHash)) {
            throw new InvalidArgumentException('Request body does not match pinned SHA-256 digest.');
        }

        return new self($bytes, $actualHash, $actualLength);
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('WireBody cannot be serialized.');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('WireBody cannot be reconstructed.');
    }

    /**
     * @return array{hash: string, length: int}
     */
    public function __debugInfo(): array
    {
        return [
            'hash' => $this->hash->hex,
            'length' => $this->length,
        ];
    }
}
