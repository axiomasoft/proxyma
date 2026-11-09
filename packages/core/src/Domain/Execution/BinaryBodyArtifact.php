<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;

final readonly class BinaryBodyArtifact implements ResponseBody
{
    public function __construct(
        public string $storageKey,
        public int $bytes,
        public ContentHash $hash,
        public ?string $mediaType = null,
    ) {
        if ($storageKey === '' || strlen($storageKey) > 512 || preg_match('/[\x00-\x1f\x7f]/', $storageKey) === 1) {
            throw new InvalidArgumentException('Binary artifact storage key must be non-empty and free of control characters.');
        }

        if ($bytes < 0) {
            throw new InvalidArgumentException('Binary artifact byte count must be non-negative.');
        }
    }

    public function byteLength(): int
    {
        return $this->bytes;
    }

    public function contentHash(): ContentHash
    {
        return $this->hash;
    }
}
