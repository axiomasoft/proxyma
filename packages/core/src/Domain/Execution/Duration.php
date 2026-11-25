<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class Duration
{
    public int $milliseconds;

    public function __construct(int $milliseconds)
    {
        if ($milliseconds < 0) {
            throw new InvalidArgumentException('Duration milliseconds must not be negative.');
        }

        $this->milliseconds = $milliseconds;
    }

    public static function milliseconds(int $milliseconds): self
    {
        return new self($milliseconds);
    }

    public static function seconds(int $seconds): self
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('Duration seconds must not be negative.');
        }

        return new self($seconds * 1000);
    }

    public static function nanoseconds(int $nanoseconds): self
    {
        if ($nanoseconds < 0) {
            throw new InvalidArgumentException('Duration nanoseconds must not be negative.');
        }

        return new self(intdiv($nanoseconds, 1_000_000));
    }

    public function plus(self $other): self
    {
        return new self($this->milliseconds + $other->milliseconds);
    }

    public function nanos(): int
    {
        return $this->milliseconds * 1_000_000;
    }
}
