<?php

declare(strict_types=1);

namespace Proxyma\Domain\Catalog;

enum ProxyProtocol: string
{
    case Http = 'http';
    case Https = 'https';
    case Socks5 = 'socks5';
    case Socks5h = 'socks5h';
}
