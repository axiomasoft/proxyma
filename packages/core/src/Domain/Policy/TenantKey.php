<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use InvalidArgumentException;
use Stringable;

final readonly class TenantKey implements Stringable
{
    public const string SYSTEM = 'system';

    public string $value;

    public function __construct(string $value)
    {
        if ($value === '*' || preg_match('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Tenant key must be a non-empty canonical identifier and never a wildcard.');
        }

        $this->value = $value;
    }

    public static function system(): self
    {
        return new self(self::SYSTEM);
    }

    public function isSystem(): bool
    {
        return $this->value === self::SYSTEM;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
