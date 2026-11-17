<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Catalog;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Domain\Catalog\Route;

#[Spi(kind: SpiKind::Call, since: '1.0')]
interface RouteAccessChecks
{
    /** Revalidate a prepared route, including its exact grant revision, before use. */
    public function assertCurrent(Route $prepared): void;
}
