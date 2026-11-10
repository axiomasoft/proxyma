<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Table;

#[Table(name: 'pxm_temporary_artifacts')]
final class TemporaryArtifactRecord extends AuthorityRecord {}
