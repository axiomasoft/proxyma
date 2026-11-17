<?php

declare(strict_types=1);

namespace Proxyma\Domain\Catalog;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum ProxyProtocol: string
{
    case Http = 'http';
    case Https = 'https';
    case Socks5 = 'socks5';
    case Socks5h = 'socks5h';
}
