<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Table;

/** @internal */
#[Table(name: 'pxm_binding_revisions')]
final class BindingRevisionRecord extends AuthorityRecord {}
