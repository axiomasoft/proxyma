<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Health;

use Illuminate\Database\Eloquent\Attributes\Table;
use Proxyma\Infrastructure\Eloquent\AuthorityRecord;

/** @internal */
#[Table(name: 'pxm_probe_claims')]
final class ProbeClaimRecord extends AuthorityRecord {}
