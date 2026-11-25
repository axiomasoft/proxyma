<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum SettingMerge: string
{
    case Replace = 'replace';
    case Minimum = 'minimum';
    case Union = 'union';
    case Intersection = 'intersection';
}
