<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use LogicException;
use Packman\Attributes\Internal;
use Proxyma\Internal\Database\RowValues;

#[Internal]
final class DatabaseClock
{
    public static function now(Connection $db): DateTimeImmutable
    {
        $row = RowValues::object($db->selectOne('SELECT clock_timestamp() AS now'));

        return new DateTimeImmutable(RowValues::colStr($row, 'now'));
    }

    public static function micros(DateTimeImmutable $instant): int
    {
        return (int) $instant->format('U') * 1000000 + (int) $instant->format('u');
    }

    public static function instant(int $micros): DateTimeImmutable
    {
        $instant = DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', intdiv($micros, 1000000), $micros % 1000000));

        return $instant === false ? throw new LogicException('Invalid microsecond instant.') : $instant->setTimezone(new DateTimeZone('UTC'));
    }
}
