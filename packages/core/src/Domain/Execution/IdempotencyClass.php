<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

enum IdempotencyClass: string
{
    case ReadOnly = 'read_only';
    case IdempotentWrite = 'idempotent_write';
    case AmbiguousWrite = 'ambiguous_write';

    public function allowsAutomaticRetry(): bool
    {
        return $this !== self::AmbiguousWrite;
    }
}
