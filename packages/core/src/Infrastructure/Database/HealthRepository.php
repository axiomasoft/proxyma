<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use DateTimeImmutable;
use Packman\Attributes\Api;
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
use Proxyma\Infrastructure\Eloquent\ImportedHealthSampleRecord;
use Proxyma\Infrastructure\Health\ProbeClaims;
use Proxyma\Internal\Database\RowValues;

#[Api(since: '1.0')]
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
        $owner = RowValues::nullableStr($s['route_owner_tenant_key'] ?? null) ?? $fact->tenant->value;
        if (($s['extends_origin_cooldown'] ?? false) === true && isset($s['origin_scope_key'], $s['cooldown_until'])) {
            if ($this->dedup($fact, 'origin_cooldown', 1)) {
                new CooldownService($this->scope)->extend($s);
            }
        }
        $probeOwned = new ProbeClaims($this->scope)->ownsCompletion($fact);
        $rawProbeEpochs = $s['probe_epochs'] ?? [];
        $probeEpochs = is_array($rawProbeEpochs) ? $rawProbeEpochs : [];
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
                $eligible = RowValues::colInt($row, 'health_epoch') === $epoch;
                if (($s['purpose'] ?? null) === 'probe' && (! $probeOwned || ! isset($probeEpochs[$key]))) {
                    $eligible = false;
                }
                if ($scope !== HealthScope::Target && RowValues::colNullableStr($row, 'route_id') !== ($s['route_id'] ?? null)) {
                    $eligible = false;
                }
                if ($scope !== HealthScope::Target) {
                    $route = $db->table($t['routes'])->where('id', RowValues::colNullableStr($row, 'route_id'))->first();
                    if ($route === null || RowValues::colNullableStr($route, 'endpoint_id') !== ($s['endpoint_id'] ?? null)
                        || RowValues::colStr($route, 'egress_key') !== ($s['egress_key'] ?? null)) {
                        $eligible = false;
                    }
                }
                if ($scope !== HealthScope::Route && (RowValues::colNullableStr($row, 'target_id') !== ($s['target_id'] ?? null)
                    || ! $db->table($t['targets'])->where('id', RowValues::colNullableStr($row, 'target_id'))->where('canonical_origin', $s['origin_value'] ?? '')->exists())) {
                    $eligible = false;
                }
                $probeUntil = RowValues::colNullableStr($row, 'probe_until');
                if (RowValues::colStr($row, 'state') === 'half_open'
                    && (($s['purpose'] ?? null) !== 'probe' || RowValues::colNullableStr($row, 'probe_token') !== ($s['probe_token'] ?? null)
                        || $probeUntil === null || new DateTimeImmutable($probeUntil) <= $now)) {
                    $eligible = false;
                }
                $signal = $signals->forScope($scope);
                $credVersion = isset($s['credential_version']) ? RowValues::int($s['credential_version']) : 1;
                if ($scope === HealthScope::Route && isset($s['endpoint_id'])) {
                    $endpoint = $db->table($t['endpoints'])->where('id', $s['endpoint_id'])->first();
                    if ($endpoint === null || (RowValues::colNullableInt($endpoint, 'credential_version') ?? 1) !== $credVersion) {
                        $eligible = false;
                    }
                }
                if (! $this->dedup($fact, $key, $epoch, $eligible) || ! $eligible) {
                    continue;
                }
                [$sample, $failures, $groups] = $this->window($scope, $key, $epoch, $now, $scope === HealthScope::Target ? $fact->tenant->value : $owner);
                $probeToken = RowValues::colNullableStr($row, 'probe_token');
                $snapshot = new HealthSnapshot(
                    new Uuid(RowValues::colStr($row, 'id')),
                    CircuitState::from(RowValues::colStr($row, 'state')),
                    RowValues::colInt($row, 'health_epoch'),
                    RowValues::colInt($row, 'state_version'),
                    RowValues::colInt($row, 'consecutive_failures'),
                    RowValues::colInt($row, 'recent_openings'),
                    RowValues::colNullableStr($row, 'reason_code'),
                    self::date(RowValues::colNullableStr($row, 'last_success_at')),
                    self::date(RowValues::colNullableStr($row, 'last_failure_at')),
                    self::date(RowValues::colNullableStr($row, 'open_until')),
                    $probeToken === null ? null : new Uuid($probeToken),
                    self::date($probeUntil),
                    self::date(RowValues::colNullableStr($row, 'stable_since')),
                    $scope === HealthScope::Target ? TargetAvailability::from(RowValues::colStr($row, 'availability')) : null,
                );
                $reason = $signal === HealthSignal::CredentialHold ? 'credential_hold:'.$credVersion : $signals->reason;
                $mutation = $this->reducer->reduce($scope, $snapshot, $signal, $reason, $now, $sample, $failures, $groups,
                    $failures > 0, self::date(RowValues::nullableStr($s['cooldown_until'] ?? null)));
                if ($mutation === null) {
                    continue;
                }
                $next = $mutation->nextSnapshot;
                $attrs = ['state' => $next->state->value, 'health_epoch' => $next->healthEpoch, 'state_version' => $next->stateVersion,
                    'consecutive_failures' => $next->consecutiveFailures, 'recent_openings' => $next->recentOpenings,
                    'reason_code' => $next->reasonCode, 'last_success_at' => $next->lastSuccessAt?->format('Y-m-d H:i:s.uP'), 'last_failure_at' => $next->lastFailureAt?->format('Y-m-d H:i:s.uP'),
                    'open_until' => $next->openUntil?->format('Y-m-d H:i:s.uP'), 'stable_since' => $next->stableSince?->format('Y-m-d H:i:s.uP'), 'probe_token' => null, 'probe_until' => null];
                if ($scope === HealthScope::Target && $next->availability !== null) {
                    $attrs['availability'] = $next->availability->value;
                }
                if ($scope === HealthScope::Route && $signal === HealthSignal::CredentialHold) {
                    $attrs['credential_endpoint_id'] = $s['endpoint_id'];
                    $attrs['credential_version'] = $credVersion;
                }
                $db->table($t[$scope->value])->where('id', RowValues::colStr($row, 'id'))->update($attrs);
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

    /** @return array{0: int, 1: int, 2: int} */
    private function window(HealthScope $scope, string $key, int $epoch, DateTimeImmutable $now, string $tenant): array
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
            $summary = RowValues::assoc(RowValues::jsonAssoc(RowValues::colStr($row, 'evidence'))['result'] ?? []);
            $signal = HealthSignals::fromSummary($summary)->forScope($scope);
            if (in_array($signal, [HealthSignal::Success, HealthSignal::Failure, HealthSignal::Maintenance], true)) {
                $attemptId = RowValues::colStr($row, 'attempt_id');
                $attempts[$attemptId] = ($attempts[$attemptId] ?? false) || $signal !== HealthSignal::Success;
                $indGroup = $summary['independence_group'] ?? null;
                if ($signal !== HealthSignal::Success && is_string($indGroup) && $indGroup !== '') {
                    $groups[$indGroup] = true;
                }
            }
        }
        $imports = $this->scope->connection->table((new ImportedHealthSampleRecord)->getTable())
            ->where('tenant_key', $tenant)
            ->where('application_key', $key)->where('health_epoch', $epoch)->where('reducer_allowed', true)
            ->where('observed_at', '>=', $now->modify('-'.$seconds.' seconds')->format('Y-m-d H:i:s.uP'))->get();
        foreach ($imports as $row) {
            $failed = RowValues::colStr($row, 'signal') !== 'success';
            $attemptId = RowValues::colStr($row, 'attempt_id');
            $attempts[$attemptId] = ($attempts[$attemptId] ?? false) || $failed;
            $indGroup = RowValues::colNullableStr($row, 'independence_group');
            if ($failed && $indGroup !== null && $indGroup !== '') {
                $groups[$indGroup] = true;
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
