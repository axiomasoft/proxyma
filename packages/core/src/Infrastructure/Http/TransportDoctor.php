<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Http;

use LogicException;
use Packman\Attributes\Api;
use Proxyma\Domain\Catalog\ProxyProtocol;

#[Api(since: '1.0')]
final class TransportDoctor
{
    public static function assertProtocol(ProxyProtocol $protocol, ?int $features = null): void
    {
        if (! extension_loaded('curl') || $protocol === ProxyProtocol::Socks5h) {
            throw new LogicException('cURL is required; remote-DNS SOCKS5h is forbidden.');
        }
        if ($features === null) {
            $version = curl_version();
            $curlFeatures = is_array($version) ? ($version['features'] ?? null) : null;
            if (! is_int($curlFeatures)) {
                throw new LogicException('Unable to inspect cURL capabilities.');
            }
            $features = $curlFeatures;
        }
        if ($protocol === ProxyProtocol::Https && ($features & CURL_VERSION_HTTPS_PROXY) === 0) {
            throw new LogicException('Selected cURL lacks HTTPS-proxy support; this protocol cannot be enabled.');
        }
    }
}
