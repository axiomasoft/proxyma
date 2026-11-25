<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
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
