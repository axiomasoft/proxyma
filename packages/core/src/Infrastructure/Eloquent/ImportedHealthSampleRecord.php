<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Table;

/** @internal Immutable reducer window imported by a drained consumer migration. */
#[Table(name: 'pxm_imported_health_samples')]
final class ImportedHealthSampleRecord extends AuthorityRecord {}
