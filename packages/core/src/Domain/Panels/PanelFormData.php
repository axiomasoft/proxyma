<?php

declare(strict_types=1);

namespace Proxyma\Domain\Panels;

use Packman\Attributes\Api;
use Packman\Panels\Data\Panel\PanelFormData as PackmanPanelFormData;
use Proxyma\Domain\Policy\SubjectPolicyBinding;

/**
 * Headless policy form projection DTO carrying resolved fields, clamping bounds, and optimistic edit version.
 */
#[Api(since: '1.0')]
final readonly class PanelFormData
{
    /** @param array<string, array{value: mixed, inherited: bool, source: string, revision: int, clamped: bool, minimum: ?int, maximum: ?int, clamped_by: ?string, clamped_revision: ?int, editable: bool, reason: string}> $fields */
    public function __construct(
        public string $panelKey,
        public string $slotKey,
        public ?SubjectPolicyBinding $binding,
        public array $fields,
        public ?string $blockedReason = null,
        public ?string $editVersion = null,
    ) {}

    public function toPackmanFormData(): PackmanPanelFormData
    {
        $changesPayload = [];

        if ($this->binding !== null) {
            foreach ($this->binding->patch->changes as $key => $change) {
                $changesPayload[$key] = [
                    'operation' => $change->operation->value,
                    'value' => $change->value,
                ];
            }
        }

        $bindingPayload = $this->binding === null
            ? null
            : [
                'binding_id' => $this->binding->bindingId->value,
                'subject_type' => $this->binding->subject->type,
                'subject_id' => $this->binding->subject->id,
                'route_profile_id' => $this->binding->routeProfileId?->value,
                'policy_key' => $this->binding->policyKey,
                'revision' => $this->binding->revision,
                'panel_key' => $this->binding->panelKey,
                'slot_key' => $this->binding->slotKey,
                'retired' => $this->binding->retired,
                'patch' => ['changes' => $changesPayload],
            ];

        return new PackmanPanelFormData(
            panelKey: $this->panelKey,
            slotKey: $this->slotKey,
            binding: $bindingPayload,
            fields: $this->fields,
            blockedReason: $this->blockedReason,
            editVersion: $this->editVersion,
        );
    }

    public static function fromPackmanFormData(PackmanPanelFormData $data, ?SubjectPolicyBinding $binding = null): self
    {
        /** @var array<string, array{value: mixed, inherited: bool, source: string, revision: int, clamped: bool, minimum: ?int, maximum: ?int, clamped_by: ?string, clamped_revision: ?int, editable: bool, reason: string}> $fields */
        $fields = $data->fields;

        return new self(
            panelKey: $data->panelKey,
            slotKey: $data->slotKey,
            binding: $binding,
            fields: $fields,
            blockedReason: $data->blockedReason,
            editVersion: $data->editVersion,
        );
    }
}
