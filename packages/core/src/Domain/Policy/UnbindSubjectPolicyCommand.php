<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use Proxyma\Domain\Catalog\CatalogMutationActor;

final readonly class UnbindSubjectPolicyCommand
{
    public function __construct(
        public SubjectRef $subject,
        public string $policyKey,
        public CatalogMutationActor $actor,
    ) {}
}
