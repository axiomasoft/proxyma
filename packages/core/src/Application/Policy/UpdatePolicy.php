<?php

declare(strict_types=1);

namespace Proxyma\Application\Policy;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Contracts\PolicyCommands;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\InvalidSettingPatch;
use Proxyma\Domain\Policy\PolicyConflict;
use Proxyma\Domain\Policy\PolicyRevision;
use Proxyma\Domain\Policy\PolicyStorageFailure;
use Proxyma\Domain\Policy\SettingPatch;
use Proxyma\Domain\Policy\UpdatePolicyCommand;
use Proxyma\Infrastructure\Audit\CatalogAudit;
use Proxyma\Infrastructure\Database\PackageSchema;

final readonly class UpdatePolicy implements PolicyCommands
{
    public function __construct(private Connection $db, private CatalogAccess $access, private SettingRegistry $registry) {}

    public function update(UpdatePolicyCommand $command): PolicyRevision
    {
        if ($this->db->transactionLevel() !== 0 || $command->actor->expectedRevision === null || $command->actor->idempotencyKey === null) {
            throw new InvalidSettingPatch;
        }
        $this->access->authorize($command->tenant, 'policy.update', $command->headId, $command->actor->actor);
        $patch = SettingPatch::decode($command->serializedPatch);
        $this->registry->validate($patch, $command->scope);
        $scopeResource = $command->scope->authorizationId($command->tenant, $command->scopeId);
        $this->access->authorize($command->tenant, 'policy.scope.'.$command->scope->value.'.edit', $scopeResource, $command->actor->actor);
        foreach ($patch->changes as $key => $change) {
            $this->access->authorize($command->tenant, $this->registry->descriptor($key)->permission, $command->headId, $command->actor->actor);
        }
        $hash = hash('sha256', json_encode(['policy.update', $command], JSON_THROW_ON_ERROR));

        try {
            return $this->db->transaction(function () use ($command, $patch, $hash): PolicyRevision {
                $t = PackageSchema::tables();
                $tenant = $command->tenant->value;
                $this->db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['proxyma:policy:'.$tenant.':'.$command->scopeKey()]);
                $key = CatalogAudit::key((string) $command->actor->idempotencyKey);
                $receipt = $this->db->table($t['catalog_command_receipts'])->where('tenant_key', $tenant)->where('idempotency_key', $key)->first();
                if ($receipt !== null) {
                    if (! hash_equals($receipt->command_hash, $hash)) {
                        throw new PolicyConflict;
                    }
                    $saved = json_decode($receipt->result, false, flags: JSON_THROW_ON_ERROR);
                    if (! is_object($saved) || ! isset($saved->head_id, $saved->revision_id)) {
                        throw new PolicyStorageFailure;
                    }
                    $revision = $this->db->table($t['policy_revisions'])->where('tenant_key', $tenant)
                        ->where('head_id', $saved->head_id)->where('id', $saved->revision_id)->first();
                    if ($revision === null || $saved->head_id !== $command->headId->value) {
                        throw new PolicyStorageFailure;
                    }
                    $result = SettingPatch::decode($revision->definition);
                    $this->registry->validate($result, $command->scope, stored: true);

                    return new PolicyRevision($command->headId, new Uuid($revision->id), (int) $revision->revision, $result);
                }
                $head = $this->db->table($t['policy_heads'])->where('tenant_key', $tenant)->where('id', $command->headId->value)->lockForUpdate()->first();
                if ($head === null) {
                    if ($command->actor->expectedRevision !== 0 || $this->db->table($t['policy_heads'])->where('tenant_key', $tenant)->where('scope_key', $command->scopeKey())->exists()) {
                        throw new PolicyConflict;
                    }
                    $previous = new SettingPatch(1, []);
                } else {
                    if ($head->scope_key !== $command->scopeKey() || (int) $head->revision !== $command->actor->expectedRevision || $head->current_revision_id === null) {
                        throw new PolicyConflict;
                    }
                    $old = $this->db->table($t['policy_revisions'])->where('tenant_key', $tenant)->where('head_id', $command->headId->value)
                        ->where('id', $head->current_revision_id)->first();
                    if ($old === null || (int) $old->revision !== (int) $head->revision) {
                        throw new PolicyStorageFailure;
                    }
                    $previous = SettingPatch::decode($old->definition);
                }
                $next = $this->registry->apply($previous, $patch, $command->scope);
                $revision = $command->actor->expectedRevision + 1;
                $revisionId = Uuid::random();
                if ($head === null) {
                    $this->db->table($t['policy_heads'])->insert(['id' => $command->headId->value, 'tenant_key' => $tenant,
                        'scope_key' => $command->scopeKey(), 'revision' => $revision]);
                }
                $this->db->table($t['policy_revisions'])->insert(['id' => $revisionId->value, 'tenant_key' => $tenant,
                    'head_id' => $command->headId->value, 'revision' => $revision, 'definition' => $next->encode()]);
                $this->db->table($t['policy_heads'])->where('tenant_key', $tenant)->where('id', $command->headId->value)
                    ->update(['revision' => $revision, 'current_revision_id' => $revisionId->value, 'updated_at' => $this->db->raw('clock_timestamp()')]);
                $fields = array_keys($patch->changes);
                sort($fields, SORT_STRING);
                $before = array_values(array_intersect($fields, array_keys($previous->changes)));
                $after = array_values(array_intersect($fields, array_keys($next->changes)));
                $auditId = Uuid::random();
                $this->db->table($t['audit_events'])->insert(['id' => $auditId->value, 'tenant_key' => $tenant,
                    'actor_type' => $command->actor->actor->type, 'actor_id' => hash('sha256', $command->actor->actor->id),
                    'reason' => hash('sha256', $command->actor->reason), 'ability' => 'policy.update', 'entity_type' => 'policy_heads',
                    'entity_id' => $command->headId->value, 'previous_revision' => $command->actor->expectedRevision, 'revision' => $revision,
                    'idempotency_key' => $key, 'command_hash' => $hash,
                    'result' => json_encode(['before_fields' => $before, 'after_fields' => $after], JSON_THROW_ON_ERROR)]);
                $this->db->table($t['catalog_command_receipts'])->insert(['tenant_key' => $tenant, 'audit_event_id' => $auditId->value,
                    'idempotency_key' => $key, 'command_hash' => $hash,
                    'result' => json_encode(['head_id' => $command->headId->value, 'revision_id' => $revisionId->value], JSON_THROW_ON_ERROR)]);

                return new PolicyRevision($command->headId, $revisionId, $revision, $next);
            }, 3);
        } catch (QueryException $failure) {
            if ($failure->getCode() === '23505') {
                throw new PolicyConflict;
            }
            throw new PolicyStorageFailure;
        }
    }
}
