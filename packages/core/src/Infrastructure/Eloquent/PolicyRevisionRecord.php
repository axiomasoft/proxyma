<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Table;
use Packman\Attributes\Internal;

/** @internal */
#[Table(name: 'pxm_policy_revisions')]
#[Internal]
final class PolicyRevisionRecord extends AuthorityRecord {}
