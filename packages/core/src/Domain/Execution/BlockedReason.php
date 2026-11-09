<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

enum BlockedReason: string
{
    case OwnerLost = 'owner_lost';
    case OwnerPaused = 'owner_paused';
    case EmptyProfile = 'empty_profile';
    case DirectRouteNotAllowed = 'direct_route_not_allowed';
    case CrossTenantDenied = 'cross_tenant_denied';
    case TargetRetired = 'target_retired';
    case UrlPolicyDenied = 'url_policy_denied';
    case CredentialHold = 'credential_hold';
    case ConfigurationHold = 'configuration_hold';
    case PolicyReview = 'policy_review';
    case WireFingerprintConflict = 'wire_fingerprint_conflict';
    case AlreadyClaimed = 'already_claimed';
}
