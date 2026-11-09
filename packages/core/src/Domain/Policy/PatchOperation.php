<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

enum PatchOperation: string
{
    case Set = 'set';
    case Inherit = 'inherit';
    case Disable = 'disable';
}
