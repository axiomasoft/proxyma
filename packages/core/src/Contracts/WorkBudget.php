<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Proxyma\Domain\Execution\Duration;

/** Project work/slice budget, sampled outside SQL; it cannot grant network authority. */
interface WorkBudget
{
    public function canStart(Duration $required): bool;
}
