<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use InvalidArgumentException;

final readonly class SubjectRef
{
    public const int MAX_PARENTS = 15;

    /** @var list<self> */
    public array $parents;

    /**
     * @param  list<self>  $parents
     */
    public function __construct(
        public TenantKey $tenant,
        public string $type,
        public string $id,
        array $parents = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $type) !== 1) {
            throw new InvalidArgumentException('Subject type must be a valid registered identifier.');
        }

        if ($id === '' || strlen($id) > 255 || preg_match('/[\x00-\x20\x7f]/', $id) === 1) {
            throw new InvalidArgumentException('Subject opaque id must be non-empty and free of whitespace or control bytes.');
        }

        if (count($parents) > self::MAX_PARENTS) {
            throw new InvalidArgumentException('Subject parent chain exceeds maximum depth of 16.');
        }

        $seen = [json_encode([$type, $id], JSON_THROW_ON_ERROR) => true];
        $normalizedParents = [];

        foreach ($parents as $parent) {
            if (! $parent->tenant->equals($tenant)) {
                throw new InvalidArgumentException('Cross-tenant parent in subject hierarchy is denied.');
            }

            if ($parent->parents !== []) {
                throw new InvalidArgumentException('Ordered parent chain must be flat; nested parent lists are ambiguous.');
            }

            $key = json_encode([$parent->type, $parent->id], JSON_THROW_ON_ERROR);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Cycle detected in subject parent chain.');
            }
            $seen[$key] = true;
            $normalizedParents[] = $parent;
        }

        $this->parents = $normalizedParents;
    }

    public function key(): string
    {
        return implode(':', array_map(rawurlencode(...), [$this->tenant->value, $this->type, $this->id]));
    }

    public function withoutParents(): self
    {
        return $this->parents === [] ? $this : new self($this->tenant, $this->type, $this->id);
    }

    public function equals(self $other): bool
    {
        if (! $this->tenant->equals($other->tenant) || $this->type !== $other->type || $this->id !== $other->id) {
            return false;
        }

        if (count($this->parents) !== count($other->parents)) {
            return false;
        }

        foreach ($this->parents as $index => $parent) {
            if (! $parent->equals($other->parents[$index])) {
                return false;
            }
        }

        return true;
    }
}
