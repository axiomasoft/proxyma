<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Proxyma\Domain\Policy\PolicyRevision;
use Proxyma\Domain\Policy\UpdatePolicyCommand;

interface PolicyCommands
{
    public function update(UpdatePolicyCommand $command): PolicyRevision;
}
