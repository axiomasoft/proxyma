<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class DecodedTextBody implements ResponseBody
{
    public ContentHash $hash;

    public function __construct(
        public string $text,
        public string $encoding = 'UTF-8',
    ) {
        if ($encoding === '' || preg_match('/^[A-Za-z0-9._-]+$/D', $encoding) !== 1) {
            throw new InvalidArgumentException('Text body encoding must be a valid charset identifier.');
        }

        $this->hash = ContentHash::of($text);
    }

    public function byteLength(): int
    {
        return strlen($this->text);
    }

    public function contentHash(): ContentHash
    {
        return $this->hash;
    }
}
