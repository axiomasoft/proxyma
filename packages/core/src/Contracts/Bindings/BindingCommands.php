<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Bindings;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Domain\Policy\BindSubjectPolicyCommand;
use Proxyma\Domain\Policy\SubjectPolicyBinding;
use Proxyma\Domain\Policy\UnbindSubjectPolicyCommand;

#[Spi(kind: SpiKind::Call, since: '1.0')]
interface BindingCommands
{
    public function bindPolicy(BindSubjectPolicyCommand $command): SubjectPolicyBinding;

    public function unbindPolicy(UnbindSubjectPolicyCommand $command): void;
}
