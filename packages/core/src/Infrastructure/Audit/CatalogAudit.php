<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Audit;

use Illuminate\Database\Connection;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantContext;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\PackageSchema;

/** @internal Журнал не содержит свободный текст, значения patch или snapshot модели. */
final readonly class CatalogAudit
{
    private const array Fields = [
        'route_health' => ['id', 'tenant_key', 'state', 'health_epoch', 'state_version', 'revision'],
        'route_target_health' => ['id', 'tenant_key', 'state', 'health_epoch', 'state_version', 'revision'],
        'target_health' => ['id', 'tenant_key', 'state', 'health_epoch', 'state_version', 'revision'],
        'budgets' => ['id', 'tenant_key', 'scope_key', 'emission_interval_us', 'burst', 'max_inflight', 'mandatory', 'revision', 'hour_limit', 'day_limit', 'quota_policy_key', 'traffic_class', 'traffic_parent_id', 'traffic_percent'],
        'endpoints' => ['id', 'tenant_key', 'protocol', 'host', 'port', 'credential_ref', 'credential_version', 'revision', 'retired_at'],
        'routes' => ['id', 'tenant_key', 'route_key', 'kind', 'endpoint_id', 'enabled', 'egress_key', 'conservative_pool', 'independence_group', 'shared_grant', 'credential_version', 'endpoint_revision', 'revision', 'retired_at'],
        'route_profiles' => ['id', 'tenant_key', 'profile_key', 'strategy_key', 'member_route_ids', 'revision', 'retired_at'],
        'targets' => ['id', 'tenant_key', 'target_key', 'canonical_origin', 'destination_policy_key', 'revision', 'retired_at'],
        'shared_route_grants' => ['id', 'tenant_key', 'consumer_tenant_key', 'route_id', 'revision', 'retired_at'],
    ];

    public function __construct(private Connection $db) {}

    public static function key(string $key): string
    {
        return hash('sha256', $key);
    }

    public static function snapshot(string $table, object $row): object
    {
        return (object) array_intersect_key((array) $row, array_flip(self::Fields[$table]));
    }

    /** @param list<string> $fields */
    public function mutation(string $table, TenantKey $tenant, Uuid $id, string $ability, CatalogMutationActor $actor, string $hash, ?object $previous, object $result, array $fields): void
    {
        $t = PackageSchema::tables();
        $auditId = Uuid::random()->value;
        $fields = array_values(array_intersect($fields, [...self::Fields[$table], 'budgets']));
        sort($fields, SORT_STRING);
        $this->db->table($t['audit_events'])->insert(['id' => $auditId, 'tenant_key' => $tenant->value,
            'actor_type' => $actor->actor->type, 'actor_id' => hash('sha256', $actor->actor->id),
            'reason' => hash('sha256', $actor->reason), 'ability' => $ability, 'entity_type' => $table, 'entity_id' => $id->value,
            'previous_revision' => $actor->expectedRevision, 'revision' => $result->revision,
            'idempotency_key' => self::key((string) $actor->idempotencyKey), 'command_hash' => $hash,
            'result' => json_encode(['before_fields' => $previous === null ? [] : $fields, 'after_fields' => $fields], JSON_THROW_ON_ERROR)]);
        $this->db->table($t['catalog_command_receipts'])->insert(['tenant_key' => $tenant->value, 'audit_event_id' => $auditId,
            'idempotency_key' => self::key((string) $actor->idempotencyKey), 'command_hash' => $hash,
            'result' => json_encode(self::snapshot($table, $result), JSON_THROW_ON_ERROR)]);
    }

    public function read(string $table, Uuid $id, string $ability, TenantContext $context, int $revision): void
    {
        $this->db->table(PackageSchema::tables()['audit_events'])->insert(['tenant_key' => $context->tenant->value,
            'actor_type' => $context->actor->type, 'actor_id' => hash('sha256', $context->actor->id),
            'reason' => hash('sha256', 'catalog-read'), 'ability' => $ability, 'entity_type' => $table, 'entity_id' => $id->value,
            'event_kind' => 'read', 'previous_revision' => $revision, 'revision' => $revision,
            'idempotency_key' => self::key(Uuid::random()->value), 'command_hash' => hash('sha256', $ability.$id->value),
            'result' => '{"before_fields":[],"after_fields":[]}']);
    }
}
