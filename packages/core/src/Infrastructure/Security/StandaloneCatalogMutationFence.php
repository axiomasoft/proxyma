<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Security;

use Illuminate\Database\Connection;
use Proxyma\Contracts\CatalogMutationFence;
use Proxyma\Domain\Catalog\CatalogMutationActor;

/** Standalone installations have one catalog authority. */
final class StandaloneCatalogMutationFence implements CatalogMutationFence
{
    public function version(): ?string
    {
        return null;
    }

    public function lock(Connection $connection, CatalogMutationActor $actor): void {}
}
