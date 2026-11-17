<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use LogicException;
use Packman\Attributes\Api;
use SensitiveParameter;

/** Bounded encoded entity; response decoding is a separate step. */
#[Api(since: '1.0')]
final readonly class RawResponseBody implements ResponseBody
{
    private ContentHash $hash;

    public function __construct(#[SensitiveParameter] private string $bytes)
    {
        $this->hash = ContentHash::of($bytes);
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    public function byteLength(): int
    {
        return strlen($this->bytes);
    }

    public function contentHash(): ContentHash
    {
        return $this->hash;
    }

    public function __debugInfo(): array
    {
        return ['length' => $this->byteLength(), 'hash' => $this->hash->hex];
    }

    public function __serialize(): array
    {
        throw new LogicException('Response bodies cannot be serialized.');
    }
}
