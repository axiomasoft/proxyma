<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\PolicyStorageFailure;
use Proxyma\Domain\Policy\SettingPatch;
use Proxyma\Domain\Policy\SubjectPolicyBinding;
use Proxyma\Domain\Policy\SubjectRef;

/** @internal */
final readonly class BindingStorage
{
    public function __construct(private Connection $db) {}

    public function query(SubjectRef $subject, string $panel, string $slot): Builder
    {
        return $this->db->table(PackageSchema::tables()['bindings'])->where('tenant_key', $subject->tenant->value)
            ->where('panel_key', $panel)->where('slot_key', $slot)->where('subject_type', $subject->type)->where('subject_id', $subject->id);
    }

    public function map(object $row, SubjectRef $subject, ?string $revisionId = null): SubjectPolicyBinding
    {
        $head = $this->db->table(PackageSchema::tables()['policy_heads'])->where('tenant_key', $subject->tenant->value)->where('id', $row->head_id)->first();
        $revision = $this->db->table(PackageSchema::tables()['policy_revisions'])->where('tenant_key', $subject->tenant->value)
            ->where('head_id', $row->head_id)->where('id', $revisionId ?? $head?->current_revision_id)->first();
        if ($revision === null) {
            throw new PolicyStorageFailure;
        }

        return new SubjectPolicyBinding(new Uuid($row->id), $subject->withoutParents(),
            $row->profile_id === null ? null : new Uuid($row->profile_id), $row->policy_key, (int) $row->revision,
            $row->panel_key, $row->slot_key, SettingPatch::decode($revision->definition), $row->retired_at !== null);
    }
}
