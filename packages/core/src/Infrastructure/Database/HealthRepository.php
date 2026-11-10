<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use DateTimeImmutable;
use Proxyma\Application\Health\CooldownService;
use Proxyma\Domain\Execution\CompletionFact;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\CircuitState;
use Proxyma\Domain\Health\HealthReducer;
use Proxyma\Domain\Health\HealthScope;
use Proxyma\Domain\Health\HealthSignal;
use Proxyma\Domain\Health\HealthSignals;
use Proxyma\Domain\Health\HealthSnapshot;
use Proxyma\Domain\Health\TargetAvailability;
use Proxyma\Infrastructure\Health\ProbeClaims;

final readonly class HealthRepository
{
    public function __construct(private ConnectionScope $scope, private HealthReducer $reducer) {}

    public function apply(CompletionFact $fact, LockedTransactionContext $transaction): void
    {
        $this->scope->assertActive($transaction);
        $db = $this->scope->connection;
        $t = PackageSchema::tables();
        $s = $fact->summary;
        $now = DatabaseClock::now($db);
        $signals = HealthSignals::fromSummary($s);
        $owner = $s['route_owner_tenant_key'] ?? $fact->tenant->value;
        if (($s['extends_origin_cooldown'] ?? false) && isset($s['origin_scope_key'], $s['cooldown_until'])) {
            if ($this->dedup($fact, 'origin_cooldown', 1)) {
                (new CooldownService($this->scope))->extend($s);
            }
        }
        $probeOwned = (new ProbeClaims($this->scope))->ownsCompletion($fact);
        foreach (HealthScope::cases() as $scope) {
            foreach ($fact->authorizationEpochs->epochs as $key => $epoch) {
                if (! str_starts_with($key, $scope->value.':')) {
                    continue;
                }
                $row = $db->table($t[$scope->value])->where('id', substr($key, strlen($scope->value) + 1))
                    ->where('tenant_key', $scope === HealthScope::Target ? $fact->tenant->value : $owner)->first();
                if ($row === null) {
                    continue;
                }
                $eligible = (int) $row->health_epoch === $epoch;
                if (($s['purpose'] ?? null) === 'probe' && (! $probeOwned || ! isset($s['probe_epochs'][$key]))) {
                    $eligible = false;
                }
                if ($scope !== HealthScope::Target && $row->route_id !== ($s['route_id'] ?? null)) {
                    $eligible = false;
                }
                if ($scope !== HealthScope::Target) {
                    $route = $db->table($t['routes'])->where('id', $row->route_id)->first();
                    if ($route === null || $route->endpoint_id !== ($s['endpoint_id'] ?? null) || $route->egress_key !== ($s['egress_key'] ?? null)) {
                        $eligible = false;
                    }
                }
                if ($scope !== HealthScope::Route && ($row->target_id !== ($s['target_id'] ?? null) || ! $db->table($t['targets'])
                    ->where('id', $row->target_id)->where('canonical_origin', $s['origin_value'] ?? '')->exists())) {
                    $eligible = false;
                }
                if ($row->state === 'half_open' && (($s['purpose'] ?? null) !== 'probe' || $row->probe_token !== ($s['probe_token'] ?? null) || $row->probe_until === null || new DateTimeImmutable($row->probe_until) <= $now)) {
                    $eligible = false;
                }
                $signal = $signals->forScope($scope);
                if ($scope === HealthScope::Route && isset($s['endpoint_id'])) {
                    $endpoint = $db->table($t['endpoints'])->where('id', $s['endpoint_id'])->first();
                    if ($endpoint === null || (int) ($endpoint->credential_version ?? 1) !== (int) ($s['credential_version'] ?? 1)) {
                        $eligible = false;
                    }
                }
                if (! $this->dedup($fact, $key, $epoch, $eligible) || ! $eligible) {
                    continue;
                }
                [$sample, $failures, $groups] = $this->window($scope, $key, $epoch, $now);
                $snapshot = new HealthSnapshot(new Uuid($row->id), CircuitState::from($row->state), (int) $row->health_epoch,
                    (int) $row->state_version, (int) $row->consecutive_failures, (int) $row->recent_openings, $row->reason_code,
                    self::date($row->last_success_at), self::date($row->last_failure_at), self::date($row->open_until),
                    $row->probe_token === null ? null : new Uuid($row->probe_token), self::date($row->probe_until), self::date($row->stable_since),
                    $scope === HealthScope::Target ? TargetAvailability::from($row->availability) : null);
                $reason = $signal === HealthSignal::CredentialHold ? 'credential_hold:'.($s['credential_version'] ?? 1) : $signals->reason;
                $mutation = $this->reducer->reduce($scope, $snapshot, $signal, $reason, $now, $sample, $failures, $groups,
                    $failures > 0, self::date($s['cooldown_until'] ?? null));
                if ($mutation === null) {
                    continue;
                }
                $next = $mutation->nextSnapshot;
                $attrs = ['state' => $next->state->value, 'health_epoch' => $next->healthEpoch, 'state_version' => $next->stateVersion,
                    'consecutive_failures' => $next->consecutiveFailures, 'recent_openings' => $next->recentOpenings,
                    'reason_code' => $next->reasonCode, 'last_success_at' => $next->lastSuccessAt?->format('Y-m-d H:i:s.uP'), 'last_failure_at' => $next->lastFailureAt?->format('Y-m-d H:i:s.uP'),
                    'open_until' => $next->openUntil?->format('Y-m-d H:i:s.uP'), 'stable_since' => $next->stableSince?->format('Y-m-d H:i:s.uP'), 'probe_token' => null, 'probe_until' => null];
                if ($scope === HealthScope::Target) {
                    $attrs['availability'] = $next->availability->value;
                }
                if ($scope === HealthScope::Route && $signal === HealthSignal::CredentialHold) {
                    $attrs['credential_endpoint_id'] = $s['endpoint_id'];
                    $attrs['credential_version'] = $s['credential_version'] ?? 1;
                }
                $db->table($t[$scope->value])->where('id', $row->id)->update($attrs);
            }
        }
    }

    private function dedup(CompletionFact $fact, string $key, int $epoch, bool $eligible = true): bool
    {
        return $this->scope->connection->table(PackageSchema::tables()['observation_applications'])->insertOrIgnore([
            'id' => Uuid::random()->value, 'tenant_key' => $fact->tenant->value, 'observation_id' => $fact->observationId->value,
            'application_key' => $key, 'health_epoch' => $epoch, 'reducer_allowed' => $eligible,
        ]) === 1;
    }

    private function window(HealthScope $scope, string $key, int $epoch, DateTimeImmutable $now): array
    {
        if ($scope === HealthScope::Route) {
            return [0, 0, 0];
        }
        $t = PackageSchema::tables();
        $seconds = $scope === HealthScope::Target ? $this->reducer->thresholds->corroborationWindowSeconds : $this->reducer->thresholds->routeTargetWindowSeconds;
        $rows = $this->scope->connection->table($t['observations'].' as o')->join($t['observation_applications'].' as a', 'a.observation_id', '=', 'o.id')
            ->where('a.application_key', $key)->where('a.health_epoch', $epoch)->where('a.reducer_allowed', true)
            ->whereRaw("o.evidence->'epochs'->>? = ?", [$key, (string) $epoch])
            ->where('o.observed_at', '>=', $now->modify('-'.$seconds.' seconds')->format('Y-m-d H:i:s.uP'))->select(['o.attempt_id', 'o.evidence'])->get();
        $groups = [];
        $attempts = [];
        foreach ($rows as $row) {
            $summary = json_decode($row->evidence, true, flags: JSON_THROW_ON_ERROR)['result'];
            $signal = HealthSignals::fromSummary($summary)->forScope($scope);
            if (in_array($signal, [HealthSignal::Success, HealthSignal::Failure, HealthSignal::Maintenance], true)) {
                $attempts[$row->attempt_id] = ($attempts[$row->attempt_id] ?? false) || $signal !== HealthSignal::Success;
                if ($signal !== HealthSignal::Success && is_string($summary['independence_group'] ?? null) && $summary['independence_group'] !== '') {
                    $groups[$summary['independence_group']] = true;
                }
            }
        }
        $sample = count($attempts);
        $failures = count(array_filter($attempts));

        return [$sample, $failures, count($groups)];
    }

    private static function date(?string $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable($value);
    }
}
