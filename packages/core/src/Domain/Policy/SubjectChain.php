<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use InvalidArgumentException;

final readonly class SubjectChain
{
    public const int MAX_DEPTH = 16;

    public TenantKey $tenant;

    public SubjectRef $leaf;

    /** @var non-empty-list<SubjectRef> */
    public array $ordered;

    /**
     * @param  list<SubjectRef>  $ancestors
     */
    public function __construct(SubjectRef $leaf, array $ancestors = [])
    {
        $rawAncestors = $ancestors !== [] ? $ancestors : $leaf->parents;
        if ($ancestors !== [] && $leaf->parents !== []) {
            throw new InvalidArgumentException('Pass ancestors either on SubjectRef or to SubjectChain, not both.');
        }

        $normalizedLeaf = $leaf->withoutParents();
        $totalCount = 1 + count($rawAncestors);
        if ($totalCount > self::MAX_DEPTH) {
            throw new InvalidArgumentException('Subject chain exceeds maximum depth of 16.');
        }

        $seen = [$normalizedLeaf->key() => true];
        $chain = [$normalizedLeaf];

        foreach ($rawAncestors as $ancestor) {
            if (! $ancestor->tenant->equals($normalizedLeaf->tenant)) {
                throw new InvalidArgumentException('SubjectChain cannot cross tenant boundaries.');
            }

            if ($ancestor->parents !== []) {
                throw new InvalidArgumentException('Ancestors in SubjectChain must not contain nested parent chains.');
            }

            if (isset($seen[$ancestor->key()])) {
                throw new InvalidArgumentException('Cycle detected in SubjectChain.');
            }
            $seen[$ancestor->key()] = true;
            $chain[] = $ancestor;
        }

        $this->tenant = $normalizedLeaf->tenant;
        $this->leaf = $normalizedLeaf;
        $this->ordered = $chain;
    }

    public static function fromSubject(SubjectRef $subject): self
    {
        return new self($subject);
    }

    /**
     * @return list<SubjectRef>
     */
    public function ancestors(): array
    {
        return array_slice($this->ordered, 1);
    }
}
