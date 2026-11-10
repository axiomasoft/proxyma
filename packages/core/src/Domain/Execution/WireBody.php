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

    public bool $replayable;

    private function __construct(
        private string $bytes,
        ContentHash $hash,
        int $length,
        bool $replayable = true,
    ) {
        $this->hash = $hash;
        $this->length = $length;
        $this->replayable = $replayable;
    }

    public static function fromString(string $bytes): self
    {
        return new self($bytes, ContentHash::of($bytes), strlen($bytes), true);
    }

    public static function fromVerified(string $bytes, ContentHash $expectedHash, ?int $expectedLength = null, bool $replayable = true): self
    {
        $actualLength = strlen($bytes);
        if ($expectedLength !== null && $actualLength !== $expectedLength) {
            throw new InvalidArgumentException('Request body byte length does not match expected length.');
        }

        $actualHash = ContentHash::of($bytes);
        if (! $actualHash->equals($expectedHash)) {
            throw new InvalidArgumentException('Request body does not match pinned SHA-256 digest.');
        }

        return new self($bytes, $actualHash, $actualLength, $replayable);
    }

    public static function fromNonReplayableStream(mixed $stream, ?ContentHash $expectedHash = null, ?int $expectedLength = null): self
    {
        return self::fromStream($stream, $expectedHash, $expectedLength, false);
    }

    public static function fromStream(mixed $stream, ?ContentHash $expectedHash = null, ?int $expectedLength = null, ?bool $replayable = null): self
    {
        if (is_resource($stream)) {
            $meta = stream_get_meta_data($stream);
            $isSeekable = $meta['seekable'];
            $bytes = stream_get_contents($stream);
            if (! is_string($bytes)) {
                throw new InvalidArgumentException('Unable to read request body stream.');
            }
            $effectiveReplayable = $replayable ?? $isSeekable;
        } elseif (is_string($stream)) {
            $bytes = $stream;
            $effectiveReplayable = $replayable ?? false;
        } else {
            throw new InvalidArgumentException('Expected a readable stream resource or byte string.');
        }

        if ($expectedHash !== null) {
            return self::fromVerified($bytes, $expectedHash, $expectedLength, $effectiveReplayable);
        }

        $actualLength = strlen($bytes);
        if ($expectedLength !== null && $actualLength !== $expectedLength) {
            throw new InvalidArgumentException('Request body byte length does not match expected length.');
        }

        return new self($bytes, ContentHash::of($bytes), $actualLength, $effectiveReplayable);
    }

    public function isReplayable(): bool
    {
        return $this->replayable;
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
