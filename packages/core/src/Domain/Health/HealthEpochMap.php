<?php

declare(strict_types=1);

namespace Proxyma\Domain\Health;

use InvalidArgumentException;

final readonly class HealthEpochMap
{
    /** @var array<string, int> */
    public array $epochs;

    /**
     * @param  array<string, int>  $epochs
     */
    public function __construct(array $epochs)
    {
        $normalized = [];
        foreach ($epochs as $scope => $epoch) {
            if ($scope === '' || $epoch < 1) {
                throw new InvalidArgumentException('Health epoch map requires non-empty scope keys and positive integer epochs.');
            }
            $normalized[$scope] = $epoch;
        }

        ksort($normalized, SORT_STRING);
        $this->epochs = $normalized;
    }

    public function matches(self $liveEpochs): bool
    {
        return $this->epochs === $liveEpochs->epochs;
    }

    public function isStaleComparedTo(self $liveEpochs): bool
    {
        foreach ($this->epochs as $scope => $pinnedEpoch) {
            $liveEpoch = $liveEpochs->epochs[$scope] ?? null;
            if ($liveEpoch === null || $liveEpoch !== $pinnedEpoch) {
                return true;
            }
        }

        return false;
    }
}
