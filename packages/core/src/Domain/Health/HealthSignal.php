<?php

declare(strict_types=1);

namespace Proxyma\Domain\Health;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum HealthSignal: string
{
    case Ignored = 'ignored';
    case Success = 'success';
    case Failure = 'failure';
    case CredentialHold = 'credential_hold';
    case Maintenance = 'maintenance';
}
