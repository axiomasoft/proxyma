<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Proxyma\Domain\Policy\BindSubjectPolicyCommand;
use Proxyma\Domain\Policy\SubjectPolicyBinding;
use Proxyma\Domain\Policy\UnbindSubjectPolicyCommand;

interface BindingCommands
{
    public function bindPolicy(BindSubjectPolicyCommand $command): SubjectPolicyBinding;

    public function unbindPolicy(UnbindSubjectPolicyCommand $command): void;
}
