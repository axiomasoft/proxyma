<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Table;
use Packman\Attributes\Internal;

/** @internal Immutable reducer window imported by a drained consumer migration. */
#[Table(name: 'pxm_imported_health_samples')]
#[Internal]
final class ImportedHealthSampleRecord extends AuthorityRecord {}
