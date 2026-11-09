<?php

declare(strict_types=1);

namespace Proxyma\Domain\Catalog;

use DateTimeImmutable;
use InvalidArgumentException;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;

final readonly class Target
{
    public function __construct(
        public Uuid $id,
        public TenantKey $tenantKey,
        public string $targetKey,
        public CanonicalOrigin $origin,
        public string $destinationPolicyKey,
        public int $revision = 1,
        public ?DateTimeImmutable $retiredAt = null,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D', $targetKey) !== 1) {
            throw new InvalidArgumentException('Target key must be a non-empty generic logical identifier.');
        }

        if (preg_match('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D', $destinationPolicyKey) !== 1) {
            throw new InvalidArgumentException('Destination policy key must be a non-empty canonical identifier.');
        }

        if ($revision < 1) {
            throw new InvalidArgumentException('Target revision must be at least 1.');
        }
    }

    public function isRetired(): bool
    {
        return $this->retiredAt !== null;
    }
}
