<?php

declare(strict_types=1);

namespace Proxyma\Database;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Schema\Contracts\Migration\SchemaSetProvider;
use Packman\Schema\Data\Migration\SchemaSet;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;

/**
 * Declares the central PostgreSQL schema set owned by `axiomasoft/proxyma`.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class ProxymaSchemaSets implements SchemaSetProvider
{
    /** @return list<SchemaSet> */
    #[Override]
    public function sets(): array
    {
        return [
            new SchemaSet(
                package: 'axiomasoft/proxyma',
                name: 'proxyma',
                scope: SchemaScope::Central,
                path: dirname(__DIR__, 2).'/database/migrations',
                version: 1,
                drivers: ['pgsql'],
                lifecycle: SchemaLifecycle::Platform,
            ),
        ];
    }
}
