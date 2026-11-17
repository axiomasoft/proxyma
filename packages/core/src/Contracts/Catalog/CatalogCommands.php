<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Catalog;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Domain\Catalog\Endpoint;
use Proxyma\Domain\Catalog\RetireCatalogEntityCommand;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Catalog\RouteProfile;
use Proxyma\Domain\Catalog\SetRouteEnabledCommand;
use Proxyma\Domain\Catalog\Target;
use Proxyma\Domain\Catalog\UpsertEndpointCommand;
use Proxyma\Domain\Catalog\UpsertRouteCommand;
use Proxyma\Domain\Catalog\UpsertRouteProfileCommand;
use Proxyma\Domain\Catalog\UpsertTargetCommand;

#[Spi(kind: SpiKind::Call, since: '1.0')]
interface CatalogCommands
{
    public function upsertEndpoint(UpsertEndpointCommand $command): Endpoint;

    public function retireEndpoint(RetireCatalogEntityCommand $command): Endpoint;

    public function upsertRoute(UpsertRouteCommand $command): Route;

    public function setRouteEnabled(SetRouteEnabledCommand $command): Route;

    public function upsertProfile(UpsertRouteProfileCommand $command): RouteProfile;

    public function upsertTarget(UpsertTargetCommand $command): Target;

    public function retireTarget(RetireCatalogEntityCommand $command): Target;
}
