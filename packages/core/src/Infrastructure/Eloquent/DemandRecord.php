<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Table;

/** @internal */
#[Table(name: 'pxm_demands')]
final class DemandRecord extends AuthorityRecord {}
