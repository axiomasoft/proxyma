<?php

declare(strict_types=1);

namespace Proxyma\Domain\Health;

use Packman\Attributes\Api;
use Proxyma\Domain\Execution\TransportFailure;

#[Api(since: '1.0')]
final readonly class HealthSignals
{
    public function __construct(public HealthSignal $route, public HealthSignal $pair, public HealthSignal $target, public string $reason) {}

    public function forScope(HealthScope $scope): HealthSignal
    {
        return match ($scope) {
            HealthScope::Route => $this->route,
            HealthScope::RouteTarget => $this->pair,
            HealthScope::Target => $this->target,
        };
    }

    /** @param array<string, mixed> $summary */
    public static function fromSummary(array $summary): self
    {
        $ignored = HealthSignal::Ignored;
        $rawFailure = $summary['failure'] ?? '';
        $failure = (is_string($rawFailure) || is_int($rawFailure)) ? TransportFailure::tryFrom($rawFailure) : null;
        $rawStatus = $summary['status'] ?? null;
        $status = is_int($rawStatus) ? $rawStatus : null;
        $reason = $failure->value ?? 'http_'.($status !== null ? (string) $status : 'unknown');
        $holdsProxyCredential = (bool) ($summary['holds_proxy_credential'] ?? false);
        $extendsCooldown = (bool) ($summary['extends_origin_cooldown'] ?? false);
        $appliesWafHold = (bool) ($summary['applies_waf_hold'] ?? false);
        $delivered = (bool) ($summary['delivered'] ?? false);
        if (isset($summary['endpoint_id']) && ($holdsProxyCredential || $status === 407 || $failure === TransportFailure::ProxyAuth)) {
            return new self(HealthSignal::CredentialHold, $ignored, $ignored, 'credential_hold');
        }
        if ($status === 429 || $extendsCooldown) {
            return new self($ignored, $ignored, $ignored, $reason);
        }
        if ($appliesWafHold && isset($summary['cooldown_until'])) {
            return new self($ignored, HealthSignal::Maintenance, $ignored, 'waf_rate_signal');
        }
        if ($failure === TransportFailure::ProxyConnect && isset($summary['endpoint_id'])) {
            return new self(HealthSignal::Failure, $ignored, $ignored, $reason);
        }
        if (in_array($failure, [TransportFailure::Connect, TransportFailure::Timeout, TransportFailure::Truncated], true)) {
            return new self($ignored, HealthSignal::Failure, HealthSignal::Failure, $reason);
        }
        if ($failure !== null || ! $delivered || $status === null || $status === 407) {
            return new self($ignored, $ignored, $ignored, $reason);
        }

        return new self(HealthSignal::Success, HealthSignal::Success,
            in_array($status, [502, 503, 504], true) ? HealthSignal::Failure : HealthSignal::Success, $reason);
    }
}
