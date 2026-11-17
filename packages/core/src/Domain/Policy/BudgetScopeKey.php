<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class BudgetScopeKey
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[a-z0-9][a-z0-9_.:-]{0,254}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Budget scope key must be canonical lowercase ASCII, 1..255 bytes.');
        }
    }
}
