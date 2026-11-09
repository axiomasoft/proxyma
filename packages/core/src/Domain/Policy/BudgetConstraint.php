<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use InvalidArgumentException;

final readonly class BudgetConstraint
{
    public string $scopeKey;

    public function __construct(
        string $scopeKey,
        public int $emissionIntervalUs,
        public int $burst = 1,
        public int $maxInflight = 1,
        public bool $mandatory = false,
    ) {
        new BudgetScopeKey($scopeKey);

        if ($emissionIntervalUs < 1 || $burst < 1 || $maxInflight < 1) {
            throw new InvalidArgumentException('Budget interval, burst, and maxInflight must be positive integers.');
        }

        $this->scopeKey = $scopeKey;
    }

    public function intersect(self $other): self
    {
        if ($this->scopeKey !== $other->scopeKey) {
            throw new InvalidArgumentException('Cannot intersect budget constraints with different scope keys.');
        }

        return new self(
            scopeKey: $this->scopeKey,
            emissionIntervalUs: max($this->emissionIntervalUs, $other->emissionIntervalUs),
            burst: min($this->burst, $other->burst),
            maxInflight: min($this->maxInflight, $other->maxInflight),
            mandatory: $this->mandatory || $other->mandatory,
        );
    }
}
