<?php

declare(strict_types=1);

namespace Proxyma\Domain\Panels;

use Proxyma\Domain\Policy\SubjectPolicyBinding;

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
}
