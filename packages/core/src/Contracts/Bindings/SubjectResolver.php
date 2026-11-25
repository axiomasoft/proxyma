<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Bindings;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\SubjectRef;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface SubjectResolver
{
    /** Root-to-parent ancestors; null means the subject does not exist in this tenant. */
    public function resolve(SubjectRef $leaf): ?SubjectChain;
}
