<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;
use Proxyma\Domain\Health\HealthEpochMap;

final readonly class PermitEvidence
{
    /** @var array<string, int> */
    public array $policyRevisions;

    /**
     * @param  array<string, int>  $policyRevisions
     */
    public function __construct(
        array $policyRevisions,
        public HealthEpochMap $healthEpochs,
        public ContentHash $wireFingerprint,
    ) {
        if ($policyRevisions === []) {
            throw new InvalidArgumentException('Permit evidence requires at least one pinned policy revision.');
        }

        $normalized = [];
        foreach ($policyRevisions as $scope => $revision) {
            if ($scope === '' || $revision < 1) {
                throw new InvalidArgumentException('Permit evidence policy revisions must map non-empty keys to positive integers.');
            }
            $normalized[$scope] = $revision;
        }

        ksort($normalized, SORT_STRING);
        $this->policyRevisions = $normalized;
    }

    public function matchesLive(self $liveEvidence): bool
    {
        return $this->policyRevisions === $liveEvidence->policyRevisions
            && $this->healthEpochs->matches($liveEvidence->healthEpochs)
            && $this->wireFingerprint->equals($liveEvidence->wireFingerprint);
    }
}
