<?php

declare(strict_types=1);

namespace Proxyma\Domain\Catalog;

enum RouteKind: string
{
    case Direct = 'direct';
    case Proxy = 'proxy';
}
