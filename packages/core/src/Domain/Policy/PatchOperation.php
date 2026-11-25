<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum PatchOperation: string
{
    case Set = 'set';
    case Inherit = 'inherit';
    case Disable = 'disable';
}
