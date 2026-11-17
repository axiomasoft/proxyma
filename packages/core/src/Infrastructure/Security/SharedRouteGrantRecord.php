<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Security;

use Illuminate\Database\Eloquent\Attributes\Table;
use Packman\Attributes\Internal;
use Proxyma\Infrastructure\Eloquent\AuthorityRecord;

/** @internal */
#[Table(name: 'pxm_shared_route_grants')]
#[Internal]
final class SharedRouteGrantRecord extends AuthorityRecord {}
