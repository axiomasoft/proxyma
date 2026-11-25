<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use Packman\Attributes\Api;
use Proxyma\Domain\Catalog\CatalogMutationActor;

#[Api(since: '1.0')]
final readonly class UnbindSubjectPolicyCommand
{
    public function __construct(
        public SubjectRef $subject,
        public string $policyKey,
        public CatalogMutationActor $actor,
        public string $panelKey = 'default',
        public string $slotKey = 'default',
    ) {}
}
