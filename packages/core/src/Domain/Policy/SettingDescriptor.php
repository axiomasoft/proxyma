<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class SettingDescriptor
{
    /**
     * @param  list<mixed>  $allowedScopes
     * @param  list<mixed>  $allowedValues
     */
    public function __construct(
        public string $key,
        public SettingType $type,
        public mixed $default,
        public bool $nullable,
        public array $allowedScopes,
        public SettingMerge $merge,
        public ?int $minimum,
        public ?int $maximum,
        public string $permission,
        public bool $sensitive = false,
        public int $schemaVersion = 1,
        public bool $optional = false,
        public array $allowedValues = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,127}$/D', $key) !== 1
            || preg_match('/(^|[._:-])(credential|credentials|secret|secrets|password|token|authorization)([._:-]|$)/', $key) === 1
            || preg_match('/^[a-z][a-z0-9_.:-]{0,127}$/D', $permission) !== 1
            || $schemaVersion !== 1 || $allowedScopes === []
            || ($minimum !== null && $maximum !== null && $minimum > $maximum)) {
            throw new InvalidSettingPatch;
        }
        $seenScopes = [];
        foreach ($allowedScopes as $scope) {
            if (! $scope instanceof PolicyScope) {
                throw new InvalidSettingPatch;
            }
            $seenScopes[] = $scope->value;
        }
        if (count(array_unique($seenScopes)) !== count($allowedScopes)) {
            throw new InvalidSettingPatch;
        }
        foreach ($allowedValues as $value) {
            if (! is_string($value) || ! self::isIdentifier($value)) {
                throw new InvalidSettingPatch;
            }
        }
        if (($type === SettingType::Identifier && $allowedValues === [])
            || ($type !== SettingType::Identifier && $allowedValues !== [])
            || (in_array($type, [SettingType::Integer, SettingType::IntegerList], true) && ($minimum === null || $maximum === null))
            || (! in_array($type, [SettingType::Integer, SettingType::IntegerList], true) && ($minimum !== null || $maximum !== null))
            || ($merge === SettingMerge::Minimum && $type !== SettingType::Integer)
            || (in_array($merge, [SettingMerge::Union, SettingMerge::Intersection], true) && ! in_array($type, [SettingType::IntegerList, SettingType::IdentifierList], true))) {
            throw new InvalidSettingPatch;
        }
        if ($sensitive && $default !== null) {
            throw new InvalidSettingPatch;
        }
        if (! $sensitive) {
            $this->validateValue($default);
        }
    }

    public function validateValue(mixed $value): void
    {
        if ($this->sensitive) {
            throw new InvalidSettingPatch;
        }
        if ($value === null) {
            if (! $this->nullable) {
                throw new InvalidSettingPatch;
            }

            return;
        }
        $valid = match ($this->type) {
            SettingType::Boolean => is_bool($value),
            SettingType::Integer => is_int($value) && $this->withinBounds($value),
            SettingType::Identifier => is_string($value) && in_array($value, $this->allowedValues, true),
            SettingType::IdentifierList => $this->validList($value, false),
            SettingType::IntegerList => $this->validList($value, true),
        };
        if (! $valid) {
            throw new InvalidSettingPatch;
        }
    }

    private function validList(mixed $value, bool $integers): bool
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 256) {
            return false;
        }

        return array_all($value, fn (mixed $item): bool => $integers ? is_int($item) && $this->withinBounds($item) : is_string($item) && self::isIdentifier($item));
    }

    private function withinBounds(int $value): bool
    {
        return ($this->minimum === null || $value >= $this->minimum) && ($this->maximum === null || $value <= $this->maximum);
    }

    private static function isIdentifier(string $value): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D', $value) === 1;
    }
}
