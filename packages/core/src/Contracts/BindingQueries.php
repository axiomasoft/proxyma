<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Proxyma\Domain\Policy\SubjectPolicyBinding;
use Proxyma\Domain\Policy\SubjectRef;

interface BindingQueries
{
    public function findBinding(SubjectRef $subject, string $panelKey = 'default', string $slotKey = 'default'): ?SubjectPolicyBinding;
}
