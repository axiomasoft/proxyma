<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Table;
use Packman\Attributes\Internal;

/** @internal */
#[Table(name: 'pxm_observation_applications')]
#[Internal]
final class ObservationApplicationRecord extends AuthorityRecord {}
