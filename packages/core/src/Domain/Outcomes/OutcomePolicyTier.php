<?php

declare(strict_types=1);

namespace Proxyma\Domain\Outcomes;

use Packman\Attributes\Api;

/**
 * Иерархия приоритетов политик исхода: security+ownership -> project -> panel -> default.
 */
#[Api(since: '1.0')]
enum OutcomePolicyTier: string
{
    case Default = 'default';
    case Panel = 'panel';
    case Project = 'project';
    case SecurityOwnership = 'security_ownership';

    public function rank(): int
    {
        return match ($this) {
            self::Default => 10,
            self::Panel => 20,
            self::Project => 30,
            self::SecurityOwnership => 40,
        };
    }
}
