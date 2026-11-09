<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use Proxyma\Domain\Execution\Uuid;

final readonly class PolicyRevision
{
    public function __construct(public Uuid $headId, public Uuid $revisionId, public int $revision, public SettingPatch $patch) {}
}
