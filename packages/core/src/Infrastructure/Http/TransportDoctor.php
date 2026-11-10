<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Http;

use LogicException;
use Proxyma\Domain\Catalog\ProxyProtocol;

final class TransportDoctor
{
    public static function assertProtocol(ProxyProtocol $protocol, ?int $features = null): void
    {
        if (! extension_loaded('curl') || $protocol === ProxyProtocol::Socks5h) {
            throw new LogicException('cURL is required; remote-DNS SOCKS5h is forbidden.');
        }
        $features ??= curl_version()['features'];
        if ($protocol === ProxyProtocol::Https && ($features & CURL_VERSION_HTTPS_PROXY) === 0) {
            throw new LogicException('Selected cURL lacks HTTPS-proxy support; this protocol cannot be enabled.');
        }
    }
}
