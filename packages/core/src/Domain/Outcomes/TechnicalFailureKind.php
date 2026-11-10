<?php

declare(strict_types=1);

namespace Proxyma\Domain\Outcomes;

enum TechnicalFailureKind: string
{
    case HttpResponse = 'http_response';
    case ProxyConnect = 'proxy_connect';
    case ProxyAuth = 'proxy_auth';
    case TlsFailure = 'tls_failure';
    case OriginTimeout = 'origin_timeout';
    case OriginConnect = 'origin_connect';
    case TruncatedResponse = 'truncated_response';
    case ByteLimitExceeded = 'byte_limit_exceeded';
    case ProtocolViolation = 'protocol_violation';
    case StorageFailure = 'storage_failure';
    case ConfigurationFailure = 'configuration_failure';
    case Cancelled = 'cancelled';
}
