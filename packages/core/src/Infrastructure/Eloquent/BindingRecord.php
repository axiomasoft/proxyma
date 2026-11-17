<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Table;
use Packman\Attributes\Internal;

/** @internal */
#[Table(name: 'pxm_bindings')]
#[Internal]
final class BindingRecord extends AuthorityRecord
{
    protected $attributes = ['panel_key' => 'default', 'slot_key' => 'default', 'policy_key' => 'default'];
}
