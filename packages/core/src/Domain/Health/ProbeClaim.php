<?php

declare(strict_types=1);

namespace Proxyma\Domain\Health;

use DateTimeImmutable;
use InvalidArgumentException;
use Packman\Attributes\Api;
use Proxyma\Domain\Execution\DemandPurpose;
use Proxyma\Domain\Execution\Uuid;

#[Api(since: '1.0')]
final readonly class ProbeClaim
{
    public HealthEpochMap $epochs;

    /** @param array<string, int> $epochs */
    public function __construct(public Uuid $token, array $epochs, public DateTimeImmutable $until,
        public DemandPurpose $purpose = DemandPurpose::Probe)
    {
        if ($epochs === [] || $purpose !== DemandPurpose::Probe || count($epochs) > 3) {
            throw new InvalidArgumentException('A probe requires a nonempty exact scope set and probe purpose.');
        }
        foreach ($epochs as $key => $epoch) {
            [$scope, $id] = array_pad(explode(':', $key, 2), 2, '');
            if (HealthScope::tryFrom($scope) === null || $epoch < 1) {
                throw new InvalidArgumentException('Invalid probe scope or epoch.');
            }
            new Uuid($id);
        }
        $this->epochs = new HealthEpochMap($epochs);
    }
}
