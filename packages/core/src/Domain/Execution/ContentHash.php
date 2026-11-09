<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;
use Stringable;

final readonly class ContentHash implements Stringable
{
    public string $hex;

    public function __construct(string $hex)
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $hex) !== 1) {
            throw new InvalidArgumentException('Expected a canonical lowercase SHA-256 hex digest.');
        }

        $this->hex = $hex;
    }

    public static function of(string $bytes): self
    {
        return new self(hash('sha256', $bytes));
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->hex, $other->hex);
    }

    public function __toString(): string
    {
        return $this->hex;
    }
}
