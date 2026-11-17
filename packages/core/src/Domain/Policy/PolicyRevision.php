<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use Packman\Attributes\Api;
use Proxyma\Domain\Execution\Uuid;

#[Api(since: '1.0')]
final readonly class PolicyRevision
{
    public function __construct(public Uuid $headId, public Uuid $revisionId, public int $revision, public SettingPatch $patch) {}
}
