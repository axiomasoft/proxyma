<?php

declare(strict_types=1);

namespace Proxyma\Domain\Budget;

use InvalidArgumentException;
use OverflowException;

final readonly class Gcra
{
    public int $toleranceUs;

    public function __construct(public int $intervalUs, public int $burst)
    {
        if ($intervalUs < 1 || $burst < 1) {
            throw new InvalidArgumentException('GCRA interval and burst must be positive.');
        }
        $this->toleranceUs = self::multiply($intervalUs, $burst - 1);
    }

    public function eligibleAt(?int $tatUs, int $nowUs): int
    {
        if ($nowUs < 0) {
            throw new InvalidArgumentException('GCRA clock must be a non-negative microsecond instant.');
        }

        return max($nowUs, max(0, $tatUs ?? 0) - $this->toleranceUs);
    }

    public function advance(?int $tatUs, int $slotUs, int $cost = 1): int
    {
        if ($cost < 1 || $slotUs < 0) {
            throw new InvalidArgumentException('GCRA cost must be positive and slot non-negative.');
        }
        $debt = self::multiply($this->intervalUs, $cost);
        $base = max($tatUs ?? 0, $slotUs);
        if ($base > PHP_INT_MAX - $debt) {
            throw new OverflowException('GCRA schedule exceeds integer microseconds.');
        }

        return $base + $debt;
    }

    private static function multiply(int $one, int $two): int
    {
        if ($two > intdiv(PHP_INT_MAX, $one)) {
            throw new OverflowException('GCRA configuration exceeds integer microseconds.');
        }

        return $one * $two;
    }
}
