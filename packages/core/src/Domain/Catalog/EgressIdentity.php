<?php

declare(strict_types=1);

namespace Proxyma\Domain\Catalog;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class EgressIdentity
{
    public string $key;

    public function __construct(string $key, public bool $conservativePoolFallback = false)
    {
        if (preg_match('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D', $key) !== 1) {
            throw new InvalidArgumentException('Egress key must be a non-empty canonical identifier.');
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key) === 1) {
            throw new InvalidArgumentException('Egress identity must not be a unique random UUID; group unknown exits conservatively by provider pool or NAT.');
        }

        $this->key = $key;
    }

    public static function explicit(string $key): self
    {
        return new self($key, false);
    }

    public static function conservativePool(string $providerOrNatPool): self
    {
        $normalized = strtolower(trim($providerOrNatPool));
        if (preg_match('/^[a-z0-9][a-z0-9_.:-]{0,95}$/D', $normalized) !== 1) {
            throw new InvalidArgumentException('Provider pool or NAT key must be a valid non-empty identifier.');
        }

        return new self('pool:'.$normalized, true);
    }

    public function equals(self $other): bool
    {
        return $this->key === $other->key;
    }
}
