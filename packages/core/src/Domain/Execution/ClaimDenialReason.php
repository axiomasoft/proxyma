<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum ClaimDenialReason: string
{
    case AlreadyClaimed = 'already_claimed';
    case OwnerLost = 'owner_lost';
    case OwnerPaused = 'owner_paused';
    case PolicyDrift = 'policy_drift';
    case HealthEpochDrift = 'health_epoch_drift';
    case CredentialDrift = 'credential_drift';
    case TargetRetired = 'target_retired';
    case WireFingerprintConflict = 'wire_fingerprint_conflict';
    case PermitExpired = 'permit_expired';
    case GuardDenied = 'guard_denied';
    case QuotaExhausted = 'quota_exhausted';
}
