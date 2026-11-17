<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Policy;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Domain\Policy\SettingDescriptor;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface SettingDefinitionProvider
{
    /** @return list<SettingDescriptor> */
    public function settings(): array;
}
