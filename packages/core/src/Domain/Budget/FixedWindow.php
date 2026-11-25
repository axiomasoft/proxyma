<?php

declare(strict_types=1);

namespace Proxyma\Domain\Budget;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class FixedWindow
{
    public DateTimeImmutable $startsAt;

    public DateTimeImmutable $endsAt;

    public function __construct(public string $kind, DateTimeImmutable $instant)
    {
        if (! in_array($kind, ['hour', 'day'], true)) {
            throw new InvalidArgumentException('Only fixed UTC hour/day windows are supported.');
        }
        $utc = $instant->setTimezone(new DateTimeZone('UTC'));
        $this->startsAt = $utc->setTime($kind === 'day' ? 0 : (int) $utc->format('H'), 0);
        $this->endsAt = $this->startsAt->modify($kind === 'day' ? '+1 day' : '+1 hour');
    }
}
