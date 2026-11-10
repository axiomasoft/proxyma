<?php

declare(strict_types=1);

namespace Proxyma\Application\Routing;

use Proxyma\Contracts\RandomIndex;

final class SecureRandomIndex implements RandomIndex
{
    public function next(int $upperExclusive): int
    {
        return random_int(0, $upperExclusive - 1);
    }
}
