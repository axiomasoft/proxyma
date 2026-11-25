<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class BudgetConstraint
{
    public string $scopeKey;

    public function __construct(
        string $scopeKey,
        public int $emissionIntervalUs,
        public int $burst = 1,
        public int $maxInflight = 1,
        public bool $mandatory = false,
        public ?int $hourLimit = null,
        public ?int $dayLimit = null,
    ) {
        new BudgetScopeKey($scopeKey);

        if ($emissionIntervalUs < 1 || $burst < 1 || $maxInflight < 1 || ($hourLimit !== null && $hourLimit < 0) || ($dayLimit !== null && $dayLimit < 0)) {
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
            hourLimit: self::tighter($this->hourLimit, $other->hourLimit),
            dayLimit: self::tighter($this->dayLimit, $other->dayLimit),
        );
    }

    private static function tighter(?int $one, ?int $two): ?int
    {
        return $one === null ? $two : ($two === null ? $one : min($one, $two));
    }
}
