<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use InvalidArgumentException;

final readonly class PolicyExplanation
{
    /** @var list<string> */
    public array $appliedLayers;

    /** @var array<string, string> */
    public array $fieldSources;

    /**
     * @param  list<string>  $appliedLayers
     * @param  array<string, string>  $fieldSources
     */
    public function __construct(
        public EffectivePolicySnapshot $snapshot,
        public SubjectChain $subjectChain,
        array $appliedLayers,
        array $fieldSources,
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

        $this->appliedLayers = $appliedLayers;
        $this->fieldSources = $normalizedSources;
    }
}
