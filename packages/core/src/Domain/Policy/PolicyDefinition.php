<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;

#[Api(since: '1.0')]
final readonly class PolicyDefinition
{
    public string $policyKey;

    public function __construct(
        string $policyKey,
        public RequestTimeoutPolicy $timeouts,
        public bool $allowDirectRoute = false,
        public string $outcomePolicyKey = 'default',
        public int $schemaVersion = 1,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D', $policyKey) !== 1) {
            throw new InvalidArgumentException('Policy key must be a valid canonical identifier.');
        }

        if (preg_match('/^[a-z0-9][a-z0-9_.:-]{0,63}$/D', $outcomePolicyKey) !== 1) {
            throw new InvalidArgumentException('Outcome policy key must be a valid canonical identifier.');
        }

        if ($schemaVersion < 1) {
            throw new InvalidArgumentException('Policy schema version must be at least 1.');
        }

        $this->policyKey = $policyKey;
    }
}
