<?php

declare(strict_types=1);

namespace Proxyma\Domain\Health;

use DateTimeImmutable;

final readonly class HealthReducer
{
    public function __construct(public HealthThresholds $thresholds = new HealthThresholds) {}

    public function reduce(HealthScope $scope, HealthSnapshot $row, HealthSignal $signal, string $reason, DateTimeImmutable $now,
        int $sample = 0, int $failures = 0, int $independentGroups = 0, bool $recentTargetFailure = false, ?DateTimeImmutable $maintenanceUntil = null): ?HealthMutation
    {
        $probe = $row->state === CircuitState::HalfOpen;
        if (str_starts_with($row->reasonCode ?? '', 'credential_hold') || $row->state === CircuitState::Open || ($signal === HealthSignal::Ignored && ! $probe)) {
            return null;
        }
        if ($signal === HealthSignal::Ignored) {
            return new HealthMutation(new HealthSnapshot($row->id, CircuitState::Unknown, $row->healthEpoch + 1, $row->stateVersion + 1,
                $row->consecutiveFailures, $row->recentOpenings, $row->reasonCode, $row->lastSuccessAt, $row->lastFailureAt,
                availability: $row->availability));
        }
        $p = $this->thresholds;
        $success = $signal === HealthSignal::Success;
        $target = $scope === HealthScope::Target;
        $consecutive = $success ? 0 : $row->consecutiveFailures + 1;
        $open = in_array($signal, [HealthSignal::CredentialHold, HealthSignal::Maintenance], true);
        if (! $success) {
            $open = $open || ($target ? $independentGroups >= 2 || $probe : ($probe || ($scope === HealthScope::Route
                ? $consecutive >= $p->globalConsecutive
                : $consecutive >= $p->routeTargetConsecutive || ($sample >= $p->routeTargetMinSample && $failures * 100 >= $sample * $p->routeTargetFailurePercent))));
        }
        $availability = $target ? ($open ? TargetAvailability::Unavailable : ((! $success || $recentTargetFailure) ? TargetAvailability::Suspect : TargetAvailability::Available)) : null;
        $until = null;
        $stable = $success ? ($row->stableSince ?? $now) : null;
        $openings = $row->recentOpenings;
        if ($open) {
            [$base, $cap] = match ($scope) {
                HealthScope::Route => [$p->globalBaseSeconds, $p->globalCapSeconds],
                HealthScope::RouteTarget => [$p->routeTargetBaseSeconds, $p->routeTargetCapSeconds],
                HealthScope::Target => [$p->originCooldownSeconds, $p->originCooldownSeconds],
            };
            $until = $signal === HealthSignal::CredentialHold ? null : $now->modify('+'.min($cap, $base * 2 ** min(20, $openings)).' seconds');
            if ($signal !== HealthSignal::CredentialHold && $maintenanceUntil !== null && $maintenanceUntil > $until) {
                $until = $maintenanceUntil;
            }
            $openings++;
            $consecutive = 0;
            $stable = null;
        } elseif ($success && $now >= $stable->modify('+'.$p->stableDecaySeconds.' seconds') && $openings > 0) {
            $openings--;
            $stable = $now;
        }

        return new HealthMutation(new HealthSnapshot($row->id, $open ? CircuitState::Open : CircuitState::Closed,
            $row->healthEpoch + ($open ? 1 : 0), $row->stateVersion + 1, $consecutive, $openings,
            $success ? null : $reason, $success ? $now : $row->lastSuccessAt, $success ? $row->lastFailureAt : $now,
            $until, stableSince: $stable, availability: $availability), $open, $signal === HealthSignal::CredentialHold);
    }
}
