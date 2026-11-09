<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\SubjectRef;

interface SubjectResolver
{
    /** Root-to-parent ancestors; null means the subject does not exist in this tenant. */
    public function resolve(SubjectRef $leaf): ?SubjectChain;
}
