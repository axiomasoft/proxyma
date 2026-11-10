<?php

declare(strict_types=1);

namespace Proxyma\Domain\Health;

use Proxyma\Domain\Execution\TransportFailure;

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

    public static function fromSummary(array $summary): self
    {
        $ignored = HealthSignal::Ignored;
        $failure = TransportFailure::tryFrom($summary['failure'] ?? '');
        $status = $summary['status'] ?? null;
        $reason = $failure?->value ?? 'http_'.($status ?? 'unknown');
        if (isset($summary['endpoint_id']) && (($summary['holds_proxy_credential'] ?? false) || $status === 407 || $failure === TransportFailure::ProxyAuth)) {
            return new self(HealthSignal::CredentialHold, $ignored, $ignored, 'credential_hold');
        }
        if ($status === 429 || ($summary['extends_origin_cooldown'] ?? false)) {
            return new self($ignored, $ignored, $ignored, $reason);
        }
        if (($summary['applies_waf_hold'] ?? false) && isset($summary['cooldown_until'])) {
            return new self($ignored, HealthSignal::Maintenance, $ignored, 'waf_rate_signal');
        }
        if ($failure === TransportFailure::ProxyConnect && isset($summary['endpoint_id'])) {
            return new self(HealthSignal::Failure, $ignored, $ignored, $reason);
        }
        if (in_array($failure, [TransportFailure::Connect, TransportFailure::Timeout, TransportFailure::Truncated], true)) {
            return new self($ignored, HealthSignal::Failure, HealthSignal::Failure, $reason);
        }
        if ($failure !== null || ! ($summary['delivered'] ?? false) || $status === null || $status === 407) {
            return new self($ignored, $ignored, $ignored, $reason);
        }

        return new self(HealthSignal::Success, HealthSignal::Success,
            in_array($status, [502, 503, 504], true) ? HealthSignal::Failure : HealthSignal::Success, $reason);
    }
}
