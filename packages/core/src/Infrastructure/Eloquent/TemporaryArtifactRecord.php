<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Table;
use Packman\Attributes\Internal;

#[Table(name: 'pxm_temporary_artifacts')]
#[Internal]
final class TemporaryArtifactRecord extends AuthorityRecord {}
