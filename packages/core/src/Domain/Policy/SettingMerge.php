<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

enum SettingMerge: string
{
    case Replace = 'replace';
    case Minimum = 'minimum';
    case Union = 'union';
    case Intersection = 'intersection';
}
