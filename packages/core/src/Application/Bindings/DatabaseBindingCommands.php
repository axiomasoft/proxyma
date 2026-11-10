<?php

declare(strict_types=1);

namespace Proxyma\Application\Bindings;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Application\Policy\SettingRegistry;
use Proxyma\Contracts\BindingCommands;
use Proxyma\Domain\Bindings\BindingIdentity;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\BindSubjectPolicyCommand;
use Proxyma\Domain\Policy\InvalidSettingPatch;
use Proxyma\Domain\Policy\PolicyConflict;
use Proxyma\Domain\Policy\PolicyScope;
use Proxyma\Domain\Policy\PolicyStorageFailure;
use Proxyma\Domain\Policy\SettingPatch;
use Proxyma\Domain\Policy\SubjectPolicyBinding;
use Proxyma\Domain\Policy\UnbindSubjectPolicyCommand;
use Proxyma\Infrastructure\Audit\CatalogAudit;
use Proxyma\Infrastructure\Cache\PolicyResolutionCache;
use Proxyma\Infrastructure\Database\BindingStorage;
use Proxyma\Infrastructure\Database\CatalogProvisioner;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Eloquent\BindingRevisionRecord;

final readonly class DatabaseBindingCommands implements BindingCommands
{
    public function __construct(
        private Connection $db,
        private CatalogAccess $access,
        private DefinitionRegistry $definitions,
        private SettingRegistry $settings,
        private ?PolicyResolutionCache $cache = null,
    ) {}

    public function bindPolicy(BindSubjectPolicyCommand $command): SubjectPolicyBinding
    {
        return $this->mutate($command);
    }

    public function unbindPolicy(UnbindSubjectPolicyCommand $command): void
    {
        $this->mutate($command);
    }

    private function mutate(BindSubjectPolicyCommand|UnbindSubjectPolicyCommand $command): SubjectPolicyBinding
    {
        if ($this->db->transactionLevel() !== 0 || $command->actor->expectedRevision === null || $command->actor->idempotencyKey === null) {
            throw new InvalidSettingPatch;
        }
        $subject = $command->subject->withoutParents();
        $id = BindingIdentity::id($subject, $command->panelKey, $command->slotKey);
        (new BindingContext($this->access, $this->definitions))->authorize($command->subject, $command->panelKey, $command->slotKey, 'update', $command->actor->actor);
        $retire = $command instanceof UnbindSubjectPolicyCommand;
        $patch = $retire ? new SettingPatch(1, []) : $command->patch;
        $this->settings->validate($patch, PolicyScope::Entity);
        $slot = $this->definitions->panel($command->panelKey)->slot($command->slotKey);
        if (array_diff(array_keys($patch->changes), $slot->settings) !== []) {
            throw new InvalidSettingPatch;
        }
        // Валидируем также policy label до первой записи.
        new SubjectPolicyBinding($id, $subject, null, $command->policyKey, panelKey: $command->panelKey, slotKey: $command->slotKey);
        $affectedFields = array_keys($patch->changes);
        if ($retire) {
            $storage = new BindingStorage($this->db);
            $existing = $storage->query($subject, $command->panelKey, $command->slotKey)->first();
            if ($existing !== null) {
                $affectedFields = array_keys($storage->map($existing, $subject)->patch->changes);
            }
        }
        foreach ($affectedFields as $field) {
            $this->access->authorize($subject->tenant, $this->settings->descriptor($field)->permission, $id, $command->actor->actor);
        }
        $hash = hash('sha256', json_encode([$retire ? 'binding.unbind' : 'binding.bind', $command, $this->definitions->fingerprint()], JSON_THROW_ON_ERROR));
        try {
            $result = $this->db->transaction(function () use ($command, $subject, $id, $retire, $patch, $hash): SubjectPolicyBinding {
                $t = PackageSchema::tables();
                $tenant = $subject->tenant->value;
                $this->db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['proxyma:policy-set:'.$tenant]);
                $key = CatalogAudit::key($command->actor->idempotencyKey);
                (new CatalogProvisioner($this->db))->lockBinding($subject, $command->panelKey, $command->slotKey);
                $storage = new BindingStorage($this->db);
                $query = $storage->query($subject, $command->panelKey, $command->slotKey);
                $row = (clone $query)->lockForUpdate()->first();
                $receipt = $this->db->table($t['catalog_command_receipts'])->where('tenant_key', $tenant)->where('idempotency_key', $key)->first();
                if ($receipt !== null) {
                    if (! hash_equals($receipt->command_hash, $hash) || $row === null) {
                        throw new PolicyConflict;
                    }
                    $saved = json_decode($receipt->result, false, flags: JSON_THROW_ON_ERROR);
                    $version = $this->db->table((new BindingRevisionRecord)->getTable())->where('tenant_key', $tenant)
                        ->where('binding_id', $row->id)->where('revision', $saved->revision)->first();
                    if ($version === null) {
                        throw new PolicyStorageFailure;
                    }
                    $snapshot = (object) (['id' => $row->id, 'panel_key' => $row->panel_key, 'slot_key' => $row->slot_key,
                        'retired_at' => $version->retired ? 'retired' : null] + (array) $version);

                    return $storage->map($snapshot, $subject, $version->policy_revision_id);
                }
                if (($row === null ? 0 : (int) $row->revision) !== $command->actor->expectedRevision || ($retire && ($row === null || $row->retired_at !== null || $row->policy_key !== $command->policyKey))) {
                    throw new PolicyConflict;
                }
                $profile = $retire ? $row->profile_id : $command->routeProfileId?->value;
                $headId = $id->value;
                $head = $this->db->table($t['policy_heads'])->where('tenant_key', $tenant)->where('id', $headId)->lockForUpdate()->first();
                if ($profile !== null && $this->db->table($t['route_profiles'])->where('tenant_key', $tenant)->where('id', $profile)->whereNull('retired_at')->lockForUpdate()->first() === null) {
                    throw new InvalidSettingPatch;
                }
                $previous = $row === null ? new SettingPatch(1, []) : $storage->map($row, $subject)->patch;
                $next = $retire ? $previous : $this->settings->apply($previous, $patch, PolicyScope::Entity);
                $revision = $command->actor->expectedRevision + 1;
                $policyRevision = $head === null ? 1 : (int) $head->revision + 1;
                $revisionId = Uuid::random();
                if ($head === null) {
                    $this->db->table($t['policy_heads'])->insert(['id' => $headId, 'tenant_key' => $tenant, 'scope_key' => 'binding:'.$headId, 'revision' => $policyRevision]);
                }
                $this->db->table($t['policy_revisions'])->insert(['id' => $revisionId->value, 'tenant_key' => $tenant, 'head_id' => $headId, 'revision' => $policyRevision, 'definition' => $next->encode()]);
                $this->db->table($t['policy_heads'])->where('tenant_key', $tenant)->where('id', $headId)->update([
                    'current_revision_id' => $revisionId->value, 'revision' => $policyRevision, 'updated_at' => $this->db->raw('clock_timestamp()')]);
                $values = ['head_id' => $headId, 'profile_id' => $profile, 'policy_key' => $command->policyKey, 'revision' => $revision,
                    'retired_at' => $retire ? $this->db->raw('clock_timestamp()') : null, 'updated_at' => $this->db->raw('clock_timestamp()')];
                if ($row === null) {
                    $this->db->table($t['bindings'])->insert($values + ['id' => $id->value, 'tenant_key' => $tenant,
                        'panel_key' => $command->panelKey, 'slot_key' => $command->slotKey, 'subject_type' => $subject->type, 'subject_id' => $subject->id]);
                } else {
                    $query->update($values);
                }
                $bindingId = $row === null ? $id->value : $row->id;
                $this->db->table((new BindingRevisionRecord)->getTable())->insert(['tenant_key' => $tenant, 'binding_id' => $bindingId,
                    'revision' => $revision, 'policy_revision_id' => $revisionId->value, 'head_id' => $headId,
                    'profile_id' => $profile, 'policy_key' => $command->policyKey, 'retired' => $retire]);
                $auditId = Uuid::random()->value;
                $this->db->table($t['audit_events'])->insert(['id' => $auditId, 'tenant_key' => $tenant,
                    'actor_type' => $command->actor->actor->type, 'actor_id' => hash('sha256', $command->actor->actor->id),
                    'reason' => hash('sha256', $command->actor->reason), 'ability' => $retire ? 'binding.unbind' : 'binding.bind',
                    'entity_type' => 'bindings', 'entity_id' => $bindingId, 'previous_revision' => $command->actor->expectedRevision,
                    'revision' => $revision, 'idempotency_key' => $key, 'command_hash' => $hash,
                    'result' => '{"before_fields":[],"after_fields":["binding"]}']);
                $this->db->table($t['catalog_command_receipts'])->insert(['tenant_key' => $tenant, 'audit_event_id' => $auditId,
                    'idempotency_key' => $key, 'command_hash' => $hash, 'result' => json_encode(['revision' => $revision], JSON_THROW_ON_ERROR)]);

                return $storage->map($query->first(), $subject);
            }, 3);
        } catch (QueryException $failure) {
            if ($failure->getCode() === '23505') {
                throw new PolicyConflict;
            }
            throw new PolicyStorageFailure;
        }
        $this->cache?->invalidateTenant($subject->tenant);

        return $result;
    }
}
