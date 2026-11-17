<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class PolicyExplanation
{
    /** @var list<string> */
    public array $appliedLayers;

    /** @var array<string, string> */
    public array $fieldSources;

    /** @var array<string, FieldExplanation> */
    public array $fields;

    /**
     * @param  list<string>  $appliedLayers
     * @param  array<string, string>  $fieldSources
     * @param  array<string, FieldExplanation>  $fields
     */
    public function __construct(
        public EffectivePolicySnapshot $snapshot,
        public SubjectChain $subjectChain,
        array $appliedLayers,
        array $fieldSources,
        array $fields = [],
        public ?EffectivePolicy $effectivePolicy = null,
    ) {
        if (! $snapshot->tenantKey->equals($subjectChain->tenant)) {
            throw new InvalidArgumentException('Policy explanation tenant must match subject chain tenant.');
        }

        foreach ($appliedLayers as $layer) {
            if ($layer === '') {
                throw new InvalidArgumentException('Applied layer names must be non-empty strings.');
            }
        }

        $normalizedSources = [];
        foreach ($fieldSources as $field => $source) {
            if ($field === '' || $source === '') {
                throw new InvalidArgumentException('Field sources must map non-empty field names to non-empty source descriptors.');
            }
            $normalizedSources[$field] = $source;
        }
        ksort($normalizedSources, SORT_STRING);

        $normalizedFields = [];
        foreach ($fields as $key => $explanation) {
            if ($key === '') {
                throw new InvalidArgumentException('PolicyExplanation fields must map non-empty keys to FieldExplanation instances.');
            }
            if (! $explanation->sensitive) {
                $normalizedFields[$key] = $explanation;
            }
        }
        ksort($normalizedFields, SORT_STRING);

        $this->appliedLayers = $appliedLayers;
        $this->fieldSources = $normalizedSources;
        $this->fields = $normalizedFields;
    }

    public function field(string $key): FieldExplanation
    {
        return $this->fields[$key] ?? throw new InvalidArgumentException("Explanation for field '{$key}' is not available or is redacted.");
    }
}
