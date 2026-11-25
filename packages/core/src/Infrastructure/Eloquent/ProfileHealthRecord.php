<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Table;
use Packman\Attributes\Internal;

/** @internal */
#[Table(name: 'pxm_profile_health')]
#[Internal]
final class ProfileHealthRecord extends AuthorityRecord {}
