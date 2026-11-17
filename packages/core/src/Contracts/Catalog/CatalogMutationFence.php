<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Catalog;

use Illuminate\Database\Connection;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Domain\Catalog\CatalogMutationActor;

/** Consumer authority selector. lock() performs SQL only, before package locks. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface CatalogMutationFence
{
    public function version(): ?string;

    public function lock(Connection $connection, CatalogMutationActor $actor): void;
}
