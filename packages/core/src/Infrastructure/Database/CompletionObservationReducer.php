<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use Illuminate\Database\Connection;
use Proxyma\Contracts\LockedCompletionReducer;
use Proxyma\Domain\Execution\CompletionFact;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;

final readonly class CompletionObservationReducer implements LockedCompletionReducer
{
    public function __construct(private ConnectionScope $scope) {}

    public function connection(): Connection
    {
        return $this->scope->connection;
    }

    public function apply(CompletionFact $fact, LockedTransactionContext $transaction): void
    {
        $this->scope->assertActive($transaction);
        $db = $this->scope->connection;
        $t = PackageSchema::tables();
        $db->table($t['observation_applications'])->insert(['id' => Uuid::random()->value, 'tenant_key' => $fact->tenant->value,
            'observation_id' => $fact->observationId->value, 'application_key' => 'completion_fact', 'health_epoch' => 1]);

        $summary = $fact->summary;
        $routeOwner = $summary['route_owner_tenant_key'] ?? $fact->tenant->value;
        $routeId = is_string($summary['route_id'] ?? null) ? $summary['route_id'] : null;
        $targetId = is_string($summary['target_id'] ?? null) ? $summary['target_id'] : null;
        $cooldownUntil = is_string($summary['cooldown_until'] ?? null) ? $summary['cooldown_until'] : null;

        if (($summary['holds_proxy_credential'] ?? false) === true && $routeId !== null) {
            $version = (int) ($summary['credential_version'] ?? 1);
            $db->table($t['route_health'])
                ->where('tenant_key', $routeOwner)
                ->where('route_id', $routeId)
                ->update([
                    'state' => 'open',
                    'reason_code' => 'credential_hold:'.$version,
                    'last_failure_at' => $db->raw('clock_timestamp()'),
                    'consecutive_failures' => $db->raw('consecutive_failures + 1'),
                    'recent_openings' => $db->raw('recent_openings + 1'),
                    'health_epoch' => $db->raw('health_epoch + 1'),
                    'state_version' => $db->raw('state_version + 1'),
                ]);
        }

        if (($summary['extends_origin_cooldown'] ?? false) === true && $cooldownUntil !== null) {
            $originScopeKey = is_string($summary['origin_scope_key'] ?? null) ? $summary['origin_scope_key'] : null;
            if ($originScopeKey !== null) {
                $db->statement(
                    "UPDATE {$t['budgets']} SET cooldown_until = GREATEST(cooldown_until, ?::timestamptz), revision = revision + 1 WHERE tenant_key IN (?, ?) AND scope_key = ?",
                    [$cooldownUntil, $fact->tenant->value, TenantKey::SYSTEM, $originScopeKey],
                );
            }
            if ($targetId !== null) {
                $db->statement(
                    "UPDATE {$t['target_health']} SET reason_code = 'origin_cooldown', open_until = GREATEST(COALESCE(open_until, '-infinity'::timestamptz), ?::timestamptz), last_failure_at = clock_timestamp(), consecutive_failures = consecutive_failures + 1, state_version = state_version + 1 WHERE tenant_key = ? AND target_id = ?
                        AND EXISTS (SELECT 1 FROM {$t['targets']} x WHERE x.id=target_id AND x.canonical_origin=?)",
                    [$cooldownUntil, $fact->tenant->value, $targetId, $summary['origin_value'] ?? ''],
                );
            }
        }

        if (($summary['applies_waf_hold'] ?? false) === true && $cooldownUntil !== null && $routeId !== null && $targetId !== null) {
            $db->statement(
                "UPDATE {$t['route_target_health']} SET reason_code = 'waf_rate_signal', open_until = GREATEST(COALESCE(open_until, '-infinity'::timestamptz), ?::timestamptz), last_failure_at = clock_timestamp(), consecutive_failures = consecutive_failures + 1, state_version = state_version + 1 WHERE tenant_key = ? AND route_id = ? AND target_id = ?",
                [$cooldownUntil, $routeOwner, $routeId, $targetId],
            );
        }
    }
}
