<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Health;

use Illuminate\Database\Eloquent\Attributes\Table;
use Packman\Attributes\Internal;
use Proxyma\Infrastructure\Eloquent\AuthorityRecord;

/** @internal */
#[Table(name: 'pxm_probe_claims')]
#[Internal]
final class ProbeClaimRecord extends AuthorityRecord {}
