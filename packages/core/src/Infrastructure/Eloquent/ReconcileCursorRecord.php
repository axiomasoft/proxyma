<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Table;

#[Table(name: 'pxm_reconcile_cursors')]
final class ReconcileCursorRecord extends AuthorityRecord {}
