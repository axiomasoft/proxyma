<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Security;

use Illuminate\Database\Connection;
use Packman\Attributes\Api;
use Proxyma\Contracts\Catalog\CatalogMutationFence;
use Proxyma\Domain\Catalog\CatalogMutationActor;

/** Standalone installations have one catalog authority. */
#[Api(since: '1.0')]
final class StandaloneCatalogMutationFence implements CatalogMutationFence
{
    public function version(): ?string
    {
        return null;
    }

    public function lock(Connection $connection, CatalogMutationActor $actor): void {}
}
