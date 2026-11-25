<?php

declare(strict_types=1);

namespace Proxyma\Application\Admission;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Proxyma\Domain\Catalog\CanonicalOrigin;

#[Api(since: '1.0')]
final readonly class TrafficSharePolicy
{
    /** @var array<string, int> */
    public array $classes;

    /** @param array<array-key, mixed> $classes */
    public function __construct(array $classes = [])
    {
        $validated = [];
        foreach ($classes as $name => $percent) {
            if (! is_string($name) || preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $name) !== 1 || ! is_int($percent) || $percent < 0 || $percent > 100) {
                throw new InvalidArgumentException('Traffic classes require canonical names and integer percentages 0..100.');
            }
            $validated[$name] = $percent;
        }
        $this->classes = $validated;
    }

    public static function hourlyLimit(int $intervalUs, int $percent): int
    {
        if ($intervalUs < 1 || $percent < 0 || $percent > 100) {
            throw new InvalidArgumentException('Invalid capacity-share rate or percentage.');
        }

        return intdiv((intdiv(59999999, $intervalUs) + 1) * 60 * $percent, 100);
    }

    public static function scope(CanonicalOrigin $origin, string $class): string
    {
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $class) !== 1) {
            throw new InvalidArgumentException('Invalid traffic class.');
        }

        return 'traffic:'.hash('sha256', $origin->budgetScopeKey()).':'.$class;
    }
}
