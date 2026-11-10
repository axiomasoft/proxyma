<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

enum TransportFailure: string
{
    case Connect = 'connect';
    case ProxyConnect = 'proxy_connect';
    case ProxyAuth = 'proxy_auth';
    case Tls = 'tls';
    case Timeout = 'timeout';
    case Truncated = 'truncated';
    case Cancelled = 'cancelled';
    case Configuration = 'configuration';
    case Storage = 'storage';
    case Protocol = 'protocol';
    case ByteLimit = 'byte_limit';

    public function isRetryable(): bool
    {
        return in_array($this, [
            self::Connect,
            self::ProxyConnect,
            self::ProxyAuth,
            self::Timeout,
            self::Truncated,
        ], true);
    }
}
