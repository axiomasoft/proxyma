<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use InvalidArgumentException;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\Uuid;

final readonly class EffectivePolicySnapshot
{
    /** @var array<string, int> */
    public array $layerRevisions;

    /**
     * @param  array<string, int>  $layerRevisions
     */
    public function __construct(
        public TenantKey $tenantKey,
        public Uuid $targetId,
        public ?Uuid $routeProfileId,
        public RequestTimeoutPolicy $timeouts,
        public bool $allowDirectRoute,
        public string $outcomePolicyKey,
        public int $effectiveRevision,
        public ContentHash $policyHash,
        array $layerRevisions = [],
    ) {
        if ($effectiveRevision < 1) {
            throw new InvalidArgumentException('Effective policy revision must be at least 1.');
        }

        if (preg_match('/^[a-z0-9][a-z0-9_.:-]{0,63}$/D', $outcomePolicyKey) !== 1) {
            throw new InvalidArgumentException('Outcome policy key must be a valid canonical identifier.');
        }

        $normalized = [];
        foreach ($layerRevisions as $key => $revision) {
            if ($key === '' || $revision < 1) {
                throw new InvalidArgumentException('Policy layer revisions must map non-empty keys to positive integer revisions.');
            }
            $normalized[$key] = $revision;
        }
        ksort($normalized, SORT_STRING);
        $this->layerRevisions = $normalized;
    }
}
