<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Table;

/** @internal */
#[Table(name: 'pxm_route_profile_members')]
final class RouteProfileMemberRecord extends AuthorityRecord {}
