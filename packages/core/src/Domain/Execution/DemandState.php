<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum DemandState: string
{
    case Active = 'active';
    case Admitted = 'admitted';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Active => in_array($next, [self::Admitted, self::Cancelled, self::Expired], true),
            self::Admitted => in_array($next, [self::Completed, self::Cancelled, self::Expired], true),
            self::Completed, self::Cancelled, self::Expired => false,
        };
    }

    public function transitionTo(self $next): self
    {
        if (! $this->canTransitionTo($next)) {
            throw new InvalidArgumentException("Illegal demand state transition from '{$this->value}' to '{$next->value}'.");
        }

        return $next;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::Expired], true);
    }

    public static function fromLegacyState(string $legacyState, bool $permitCompleted = false): self
    {
        return match ($legacyState) {
            'queued' => self::Active,
            'granted' => $permitCompleted ? self::Completed : self::Admitted,
            'cancelled' => self::Cancelled,
            'expired' => self::Expired,
            default => throw new InvalidArgumentException("Unsupported legacy demand state '{$legacyState}'."),
        };
    }
}
