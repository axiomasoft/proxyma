<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Catalog;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Domain\Catalog\RetireCatalogEntityCommand;
use Proxyma\Domain\Catalog\SharedRouteGrant;
use Proxyma\Domain\Catalog\ShareRouteCommand;

#[Spi(kind: SpiKind::Call, since: '1.0')]
interface SharedRouteCommands
{
    public function shareRoute(ShareRouteCommand $command): SharedRouteGrant;

    /** tenantKey is the grant owner; revocation advances its revision permanently. */
    public function revokeRouteGrant(RetireCatalogEntityCommand $command): SharedRouteGrant;
}
