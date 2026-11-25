<?php

declare(strict_types=1);

namespace Proxyma\Database;

use Illuminate\Container\Container;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Schema\Data\Compiler\TableNames;
use Packman\Schema\Laravel\PackmanMigration;
use Proxyma\Configuration\ProxymaOptions;

/**
 * Base migration for Proxyma schema sets extending {@see PackmanMigration}.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
abstract class ProxymaMigration extends PackmanMigration
{
    #[Override]
    public function getConnection(): ?string
    {
        $container = Container::getInstance();

        if ($container->bound(TableNames::class)) {
            $schemaConnection = parent::getConnection();

            if ($schemaConnection !== null) {
                return $schemaConnection;
            }
        }

        if ($container->bound(ProxymaOptions::class)) {
            return $container->make(ProxymaOptions::class)->connection;
        }

        return 'pgsql';
    }
}
