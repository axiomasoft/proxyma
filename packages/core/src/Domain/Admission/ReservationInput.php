<?php

declare(strict_types=1);

namespace Proxyma\Domain\Admission;

use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Catalog\RouteProfile;
use Proxyma\Domain\Catalog\Target;
use Proxyma\Domain\Execution\OperationCorrelation;
use Proxyma\Domain\Execution\OwnerSnapshot;
use Proxyma\Domain\Execution\RouteSelection;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Policy\BudgetConstraint;
use Proxyma\Domain\Policy\EffectivePolicy;

final readonly class ReservationInput
{
    /** @param array<string, BudgetConstraint> $constraints */
    public function __construct(public WireRequest $request, public OperationCorrelation $operation, public RouteSelection $selection,
        public Route $route, public RouteProfile $profile, public Target $target, public EffectivePolicy $policy,
        public OwnerSnapshot $ownerSnapshot, public array $constraints, public bool $workFits) {}

    /** @return list<string> */
    public function additionalScopes(): array
    {
        return array_keys($this->constraints);
    }
}
