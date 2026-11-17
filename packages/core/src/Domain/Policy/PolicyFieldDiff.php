<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class PolicyFieldDiff
{
    public function __construct(
        public string $key,
        public mixed $beforeValue,
        public mixed $afterValue,
        public bool $beforeDisabled,
        public bool $afterDisabled,
        public string $beforeSourceScope,
        public string $afterSourceScope,
        public bool $clamped,
        public string $reason,
    ) {
        if ($key === '' || $beforeSourceScope === '' || $afterSourceScope === '' || $reason === '') {
            throw new InvalidArgumentException('PolicyFieldDiff requires non-empty key, source scopes, and reason.');
        }
    }
}
