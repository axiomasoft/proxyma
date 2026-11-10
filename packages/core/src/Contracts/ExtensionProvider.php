<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Proxyma\Application\Extensions\DefinitionRegistry;

/** Trusted PHP boot definitions; registration must not perform SQL, secret or network I/O. */
interface ExtensionProvider
{
    public function register(DefinitionRegistry $registry): void;
}
