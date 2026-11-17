<?php

declare(strict_types=1);

namespace Proxyma\Application\Routing;

use Packman\Attributes\Api;
use Proxyma\Contracts\Routing\RandomIndex;

#[Api(since: '1.0')]
final class SecureRandomIndex implements RandomIndex
{
    public function next(int $upperExclusive): int
    {
        return random_int(0, $upperExclusive - 1);
    }
}
