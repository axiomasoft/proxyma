<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Proxyma\Domain\Catalog\Route;

interface RouteAccessChecks
{
    /** Revalidate a prepared route, including its exact grant revision, before use. */
    public function assertCurrent(Route $prepared): void;
}
