<?php

declare(strict_types=1);

namespace Consumer;

use Proxyma\Contracts\SubjectResolver;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\SubjectRef;

final class ConsumerSubjects implements SubjectResolver
{
    public function resolve(SubjectRef $leaf): ?SubjectChain
    {
        return $leaf->type === 'document' && $leaf->id === 'fixture' ? new SubjectChain($leaf) : null;
    }
}
