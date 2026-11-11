<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;

final readonly class ResponseProfile
{
    public function __construct(
        public string $kind = 'raw',
        public int $maxWireBytes = 1048576,
        public int $maxEntityBytes = 1048576,
        public int $maxTextBytes = 1048576,
        public ?string $charsetDefault = 'UTF-8',
    ) {
        if (! in_array($kind, ['raw', 'text', 'binary'], true) || min($maxWireBytes, $maxEntityBytes, $maxTextBytes) < 1
            || max($maxWireBytes, $maxEntityBytes, $maxTextBytes) > ($kind === 'binary' ? 268435456 : 67108864)) {
            throw new InvalidArgumentException('Invalid bounded response profile.');
        }
    }
}
