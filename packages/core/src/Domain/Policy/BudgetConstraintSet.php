<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use InvalidArgumentException;

final readonly class BudgetConstraintSet
{
    /** @var array<string, BudgetConstraint> */
    public array $byScopeKey;

    /**
     * @param  list<BudgetConstraint>  $mandatoryConstraints
     * @param  list<BudgetConstraint>  $contributedConstraints
     */
    public static function mergeMandatoryWithContributed(array $mandatoryConstraints, array $contributedConstraints = []): self
    {
        if ($mandatoryConstraints === []) {
            throw new InvalidArgumentException('Core admission requires at least one mandatory budget constraint.');
        }

        $merged = [];
        foreach ($mandatoryConstraints as $constraint) {
            $mandatory = $constraint->mandatory ? $constraint : new BudgetConstraint(
                $constraint->scopeKey,
                $constraint->emissionIntervalUs,
                $constraint->burst,
                $constraint->maxInflight,
                true,
                $constraint->hourLimit,
                $constraint->dayLimit,
            );
            $merged[$mandatory->scopeKey] = isset($merged[$mandatory->scopeKey])
                ? $merged[$mandatory->scopeKey]->intersect($mandatory)
                : $mandatory;
        }

        foreach ($contributedConstraints as $contributed) {
            // Contributors can only add new scopes or tighten existing mandatory scopes, never relax or remove them.
            $merged[$contributed->scopeKey] = isset($merged[$contributed->scopeKey])
                ? $merged[$contributed->scopeKey]->intersect($contributed)
                : $contributed;
        }

        ksort($merged, SORT_STRING);

        return new self($merged);
    }

    /**
     * @param  array<string, BudgetConstraint>  $byScopeKey
     */
    private function __construct(array $byScopeKey)
    {
        $this->byScopeKey = $byScopeKey;
    }

    /**
     * Returns scope keys ordered in C collation order.
     *
     * @return list<string>
     */
    public function orderedScopeKeys(): array
    {
        return array_keys($this->byScopeKey);
    }
}
