<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use InvalidArgumentException;

final readonly class FieldExplanation
{
    public mixed $effectiveValue;

    /** @var list<FieldProvenanceStep> */
    public array $provenance;

    /**
     * @param  list<FieldProvenanceStep>  $provenance
     */
    public function __construct(
        public string $key,
        mixed $effectiveValue,
        public string $sourceScope,
        public PolicyLayerKind $sourceLayer,
        public int $sourceRevision,
        public bool $inherited,
        public bool $explicit,
        public bool $disabled,
        public bool $clamped,
        public bool $editable,
        public string $permission,
        public string $reason,
        public ?int $minimumBound = null,
        public ?int $maximumBound = null,
        public ?string $clampedByScope = null,
        public ?int $clampedByRevision = null,
        public bool $sensitive = false,
        array $provenance = [],
    ) {
        if ($key === '' || $sourceScope === '' || $permission === '' || $reason === '' || $sourceRevision < 1) {
            throw new InvalidArgumentException('FieldExplanation requires non-empty key, sourceScope, permission, reason, and positive sourceRevision.');
        }

        $this->effectiveValue = $sensitive ? null : $effectiveValue;
        $this->provenance = $provenance;
    }
}
