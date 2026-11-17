<?php

declare(strict_types=1);

namespace Proxyma\Filament\Internal;

use InvalidArgumentException;
use Packman\Attributes\Internal;

/** Typed scalar, array, and query-row accessors for Filament components. */
#[Internal]
final class FilamentValue
{
    public static function str(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw new InvalidArgumentException('Expected string value.');
    }

    public static function nullableStr(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::str($value);
    }

    public static function int(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidArgumentException('Expected integer value.');
    }

    public static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::int($value);
    }

    public static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (in_array($value, [1, '1', 'true', 't'], true)) {
            return true;
        }

        if (in_array($value, [0, '0', 'false', 'f'], true)) {
            return false;
        }

        throw new InvalidArgumentException('Expected boolean value.');
    }

    /** @return array<string, mixed> */
    public static function assoc(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Expected associative array.');
        }

        $normalized = [];

        foreach ($value as $key => $entry) {
            $normalized[(string) $key] = $entry;
        }

        return $normalized;
    }

    /** @return list<string> */
    public static function strList(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Expected list of strings.');
        }

        $items = [];

        foreach ($value as $entry) {
            $items[] = self::str($entry);
        }

        return $items;
    }

    public static function col(object $row, string $column): mixed
    {
        return get_object_vars($row)[$column] ?? null;
    }

    public static function colStr(object $row, string $column): string
    {
        return self::str(self::col($row, $column));
    }

    public static function colNullableStr(object $row, string $column): ?string
    {
        return self::nullableStr(self::col($row, $column));
    }

    public static function colInt(object $row, string $column): int
    {
        return self::int(self::col($row, $column));
    }

    public static function colNullableInt(object $row, string $column): ?int
    {
        return self::nullableInt(self::col($row, $column));
    }
}
