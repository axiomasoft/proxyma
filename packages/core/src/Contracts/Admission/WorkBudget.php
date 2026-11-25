<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Admission;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Domain\Execution\Duration;

/** Project work/slice budget, sampled outside SQL; it cannot grant network authority. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface WorkBudget
{
    public function canStart(Duration $required): bool;
}
