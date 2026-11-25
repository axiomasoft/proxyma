<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Bindings;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Domain\Policy\SubjectPolicyBinding;
use Proxyma\Domain\Policy\SubjectRef;

#[Spi(kind: SpiKind::Call, since: '1.0')]
interface BindingQueries
{
    public function findBinding(SubjectRef $subject, string $panelKey = 'default', string $slotKey = 'default'): ?SubjectPolicyBinding;
}
