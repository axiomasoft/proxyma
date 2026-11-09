<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use InvalidArgumentException;
use Proxyma\Domain\Execution\Uuid;

final readonly class SubjectPolicyBinding
{
    public function __construct(
        public Uuid $bindingId,
        public SubjectRef $subject,
        public ?Uuid $routeProfileId,
        public string $policyKey,
        public int $revision = 1,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D', $policyKey) !== 1) {
            throw new InvalidArgumentException('Bound policy key must be a valid canonical identifier.');
        }

        if ($revision < 1) {
            throw new InvalidArgumentException('Binding revision must be at least 1.');
        }
    }
}
