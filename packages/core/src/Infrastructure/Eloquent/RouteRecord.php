<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Table;

/** @internal */
#[Table(name: 'pxm_routes')]
final class RouteRecord extends AuthorityRecord {}
