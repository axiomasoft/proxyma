<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use InvalidArgumentException;

final readonly class PolicyPreview
{
    /** @var array<string, PolicyFieldDiff> */
    public array $diff;

    /** @var array<string, FieldExplanation> */
    public array $constraints;

    /** @var array<string, BudgetConstraint> */
    public array $budgets;

    /**
     * @param  array<string, PolicyFieldDiff>  $diff
     * @param  array<string, FieldExplanation>  $constraints
     * @param  array<string, BudgetConstraint>  $budgets
     */
    public function __construct(
        public PolicyScope $targetScope,
        public string $targetScopeId,
        public EffectivePolicy $before,
        public EffectivePolicy $after,
        array $diff,
        array $constraints,
        array $budgets,
    ) {
        if ($targetScopeId === '') {
            throw new InvalidArgumentException('PolicyPreview requires a non-empty targetScopeId.');
        }

        ksort($diff, SORT_STRING);
        $this->diff = $diff;

        ksort($constraints, SORT_STRING);
        $this->constraints = $constraints;

        ksort($budgets, SORT_STRING);
        $this->budgets = $budgets;
    }
}
