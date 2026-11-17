<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\PolicyStorageFailure;
use Proxyma\Domain\Policy\SettingPatch;
use Proxyma\Domain\Policy\SubjectPolicyBinding;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Internal\Database\RowValues;

/** @internal */
#[Internal]
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
        $headId = RowValues::colStr($row, 'head_id');
        $head = $this->db->table(PackageSchema::tables()['policy_heads'])->where('tenant_key', $subject->tenant->value)->where('id', $headId)->first();
        $resolvedRevisionId = $revisionId ?? ($head === null ? null : RowValues::colNullableStr($head, 'current_revision_id'));
        $revision = $this->db->table(PackageSchema::tables()['policy_revisions'])->where('tenant_key', $subject->tenant->value)
            ->where('head_id', $headId)->where('id', $resolvedRevisionId)->first();
        if ($revision === null) {
            throw new PolicyStorageFailure;
        }
        $profileId = RowValues::colNullableStr($row, 'profile_id');

        return new SubjectPolicyBinding(
            new Uuid(RowValues::colStr($row, 'id')),
            $subject->withoutParents(),
            $profileId === null ? null : new Uuid($profileId),
            RowValues::colStr($row, 'policy_key'),
            RowValues::colInt($row, 'revision'),
            RowValues::colStr($row, 'panel_key'),
            RowValues::colStr($row, 'slot_key'),
            SettingPatch::decode(RowValues::colStr($revision, 'definition')),
            RowValues::col($row, 'retired_at') !== null,
        );
    }
}
