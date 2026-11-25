<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Extensions;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Application\Extensions\DefinitionRegistry;

/** Packman slot contract contributing structured Proxyma definitions without SQL, secret, or network I/O. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ExtensionProvider
{
    public function register(DefinitionRegistry $registry): void;
}
