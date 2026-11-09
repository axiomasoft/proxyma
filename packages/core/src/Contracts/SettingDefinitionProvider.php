<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Proxyma\Domain\Policy\SettingDescriptor;

interface SettingDefinitionProvider
{
    /** @return list<SettingDescriptor> */
    public function settings(): array;
}
