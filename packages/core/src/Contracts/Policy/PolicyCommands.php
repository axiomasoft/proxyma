<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Policy;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Domain\Policy\PolicyRevision;
use Proxyma\Domain\Policy\UpdatePolicyCommand;

#[Spi(kind: SpiKind::Call, since: '1.0')]
interface PolicyCommands
{
    public function update(UpdatePolicyCommand $command): PolicyRevision;
}
