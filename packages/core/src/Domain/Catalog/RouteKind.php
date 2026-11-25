<?php

declare(strict_types=1);

namespace Proxyma\Domain\Catalog;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum RouteKind: string
{
    case Direct = 'direct';
    case Proxy = 'proxy';
}
