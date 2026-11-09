<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\SubjectRef;

interface SubjectResolver
{
    public function resolve(SubjectRef $leaf): SubjectChain;
}
