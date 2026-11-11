<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Illuminate\Database\Connection;
use Proxyma\Domain\Catalog\CatalogMutationActor;

/** Consumer authority selector. lock() performs SQL only, before package locks. */
interface CatalogMutationFence
{
    public function version(): ?string;

    public function lock(Connection $connection, CatalogMutationActor $actor): void;
}
