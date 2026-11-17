<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Routing;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface RandomIndex
{
    /** Return a uniformly distributed integer in [0, $upperExclusive). */
    public function next(int $upperExclusive): int;
}
