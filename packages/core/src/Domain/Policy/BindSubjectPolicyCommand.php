<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Execution\Uuid;

final readonly class BindSubjectPolicyCommand
{
    public function __construct(
        public SubjectRef $subject,
        public ?Uuid $routeProfileId,
        public string $policyKey,
        public CatalogMutationActor $actor,
        public string $panelKey = 'default',
        public string $slotKey = 'default',
        public SettingPatch $patch = new SettingPatch(1, []),
    ) {}
}
