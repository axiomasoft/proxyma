<?php

declare(strict_types=1);

namespace Proxyma\Internal\Database;

use Packman\Attributes\Internal;
use UnexpectedValueException;

/** Typed scalar, JSON, and database-row extraction helpers. */
#[Internal]
final class RowValues
{
    public static function str(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw new UnexpectedValueException('Expected string database value.');
    }

    public static function nullableStr(mixed $value): ?string
    {
        return $value === null ? null : self::str($value);
    }

    public static function int(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1) {
            return (int) $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        throw new UnexpectedValueException('Expected integer database value.');
    }

    public static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : self::int($value);
    }

    public static function float(mixed $value): float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw new UnexpectedValueException('Expected numeric database value.');
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

        throw new UnexpectedValueException('Expected boolean database value.');
    }

    public static function object(mixed $value): object
    {
        if (! is_object($value)) {
            throw new UnexpectedValueException('Expected object row.');
        }

        return $value;
    }

    public static function nullableObject(mixed $value): ?object
    {
        return $value === null ? null : self::object($value);
    }

    public static function col(object $row, string $column): mixed
    {
        return get_object_vars($row)[$column] ?? null;
    }

    public static function hasCol(object $row, string $column): bool
    {
        return array_key_exists($column, get_object_vars($row));
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

    public static function colFloat(object $row, string $column): float
    {
        return self::float(self::col($row, $column));
    }

    public static function colBool(object $row, string $column): bool
    {
        return self::bool(self::col($row, $column));
    }

    /** @return array<string, mixed> */
    public static function assoc(mixed $value): array
    {
        if (! is_array($value)) {
            throw new UnexpectedValueException('Expected associative array.');
        }

        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    /** @return list<string> */
    public static function strList(mixed $value): array
    {
        if (! is_array($value)) {
            throw new UnexpectedValueException('Expected string list.');
        }

        $out = [];
        foreach ($value as $item) {
            $out[] = self::str($item);
        }

        return $out;
    }

    /** @return list<int> */
    public static function intList(mixed $value): array
    {
        if (! is_array($value)) {
            throw new UnexpectedValueException('Expected integer list.');
        }

        $out = [];
        foreach ($value as $item) {
            $out[] = self::int($item);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function jsonAssoc(string $json): array
    {
        return self::assoc(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }
}
