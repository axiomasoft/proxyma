<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use DateTimeImmutable;
use DateTimeZone;

final readonly class RetryAt
{
    public DateTimeImmutable $value;

    public function __construct(DateTimeImmutable $value)
    {
        $this->value = $value->setTimezone(new DateTimeZone('UTC'));
    }

    public function isAfter(DateTimeImmutable $instant): bool
    {
        return $this->value > $instant->setTimezone(new DateTimeZone('UTC'));
    }
}
