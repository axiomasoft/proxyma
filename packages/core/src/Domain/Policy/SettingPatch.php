<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use JsonException;
use stdClass;

final readonly class SettingPatch
{
    /** @var array<string, SettingChange> */
    public array $changes;

    /** @param array<array-key, mixed> $changes */
    public function __construct(public int $schemaVersion, array $changes)
    {
        if ($schemaVersion !== 1 || count($changes) > 256) {
            throw new InvalidSettingPatch;
        }
        $checked = [];
        foreach ($changes as $key => $change) {
            if (! is_string($key) || ! $change instanceof SettingChange) {
                throw new InvalidSettingPatch;
            }
            $checked[$key] = $change;
        }
        $this->changes = $checked;
    }

    public static function decode(string $json): self
    {
        if (strlen($json) > 65536) {
            throw new InvalidSettingPatch;
        }
        try {
            $data = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidSettingPatch;
        }
        self::rejectDuplicateKeys($json);
        if (! $data instanceof stdClass || ! self::keys($data, ['schema_version', 'operations'])
            || ! is_int($data->schema_version) || ! $data->operations instanceof stdClass) {
            throw new InvalidSettingPatch;
        }
        $changes = [];
        foreach (get_object_vars($data->operations) as $key => $entry) {
            if (! $entry instanceof stdClass || ! isset($entry->op) || ! is_string($entry->op)) {
                throw new InvalidSettingPatch;
            }
            $changes[$key] = match ($entry->op) {
                'set' => self::keys($entry, ['op', 'value']) ? SettingChange::set($entry->value) : throw new InvalidSettingPatch,
                'inherit' => self::keys($entry, ['op']) ? SettingChange::inherit() : throw new InvalidSettingPatch,
                'disable' => self::keys($entry, ['op']) ? SettingChange::disable() : throw new InvalidSettingPatch,
                default => throw new InvalidSettingPatch,
            };
        }

        return new self($data->schema_version, $changes);
    }

    public function encode(): string
    {
        $operations = [];
        foreach ($this->changes as $key => $change) {
            $operations[$key] = $change->toArray();
        }
        ksort($operations, SORT_STRING);

        try {
            $json = json_encode(['schema_version' => $this->schemaVersion, 'operations' => (object) $operations], JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidSettingPatch;
        }
        if (strlen($json) > 65536) {
            throw new InvalidSettingPatch;
        }

        return $json;
    }

    /** @param list<string> $keys */
    private static function keys(stdClass $object, array $keys): bool
    {
        $actual = array_keys(get_object_vars($object));
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }

    private static function rejectDuplicateKeys(string $json): void
    {
        preg_match_all('/"(?:\\\\.|[^"\\\\])*"|[{}\[\]:,]/s', $json, $matches);
        $tokens = $matches[0];
        $stack = [];
        foreach ($tokens as $index => $token) {
            if ($token === '{' || $token === '[') {
                $stack[] = ['object' => $token === '{', 'keys' => []];
            } elseif ($token === '}' || $token === ']') {
                array_pop($stack);
            } elseif (str_starts_with($token, '"') && ($tokens[$index + 1] ?? null) === ':') {
                $last = count($stack) - 1;
                $key = json_decode($token, true, flags: JSON_THROW_ON_ERROR);
                if ($last < 0 || ! $stack[$last]['object'] || isset($stack[$last]['keys'][$key])) {
                    throw new InvalidSettingPatch;
                }
                $stack[$last]['keys'][$key] = true;
            }
        }
    }
}
