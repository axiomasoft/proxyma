<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

interface RandomIndex
{
    /** Return a uniformly distributed integer in [0, $upperExclusive). */
    public function next(int $upperExclusive): int;
}
