<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Extensions;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Application\Extensions\DefinitionRegistry;

/** Trusted PHP boot definitions; registration must not perform SQL, secret or network I/O. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ExtensionProvider
{
    public function register(DefinitionRegistry $registry): void;
}
