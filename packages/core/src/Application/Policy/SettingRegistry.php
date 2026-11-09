<?php

declare(strict_types=1);

namespace Proxyma\Application\Policy;

use Proxyma\Domain\Policy\InvalidSettingPatch;
use Proxyma\Domain\Policy\PatchOperation;
use Proxyma\Domain\Policy\PolicyScope;
use Proxyma\Domain\Policy\SettingDescriptor;
use Proxyma\Domain\Policy\SettingPatch;

final readonly class SettingRegistry
{
    /** @var array<string, SettingDescriptor> */
    private array $descriptors;

    /** @param list<mixed> $descriptors */
    public function __construct(array $descriptors)
    {
        $indexed = [];
        foreach ($descriptors as $descriptor) {
            if (! $descriptor instanceof SettingDescriptor || isset($indexed[$descriptor->key])) {
                throw new InvalidSettingPatch;
            }
            $indexed[$descriptor->key] = $descriptor;
        }
        ksort($indexed, SORT_STRING);
        $this->descriptors = $indexed;
    }

    public function descriptor(string $key): SettingDescriptor
    {
        return $this->descriptors[$key] ?? throw new InvalidSettingPatch;
    }

    /** @return list<SettingDescriptor> */
    public function all(): array
    {
        return array_values($this->descriptors);
    }

    public function fingerprint(): string
    {
        $definitions = [];
        foreach ($this->descriptors as $descriptor) {
            $scopes = [];
            foreach ($descriptor->allowedScopes as $scope) {
                if (! $scope instanceof PolicyScope) {
                    throw new InvalidSettingPatch;
                }
                $scopes[] = $scope->value;
            }
            $values = $descriptor->allowedValues;
            sort($scopes, SORT_STRING);
            sort($values, SORT_STRING);
            $definitions[$descriptor->key] = [
                'type' => $descriptor->type->value, 'default' => $descriptor->default, 'nullable' => $descriptor->nullable,
                'scopes' => $scopes, 'merge' => $descriptor->merge->value, 'minimum' => $descriptor->minimum,
                'maximum' => $descriptor->maximum, 'permission' => $descriptor->permission, 'sensitive' => $descriptor->sensitive,
                'version' => $descriptor->schemaVersion, 'optional' => $descriptor->optional, 'allowed_values' => $values,
            ];
        }

        return hash('sha256', json_encode($definitions, JSON_THROW_ON_ERROR));
    }

    public function validate(SettingPatch $patch, PolicyScope $scope, bool $stored = false): void
    {
        foreach ($patch->changes as $key => $change) {
            $descriptor = $this->descriptor($key);
            if ($descriptor->schemaVersion !== $patch->schemaVersion || ! in_array($scope, $descriptor->allowedScopes, true)) {
                throw new InvalidSettingPatch;
            }
            if ($change->operation === PatchOperation::Inherit) {
                if ($stored) {
                    throw new InvalidSettingPatch;
                }

                continue;
            }
            if ($descriptor->sensitive) {
                throw new InvalidSettingPatch;
            }
            if ($change->operation === PatchOperation::Disable) {
                if (! $descriptor->optional) {
                    throw new InvalidSettingPatch;
                }

                continue;
            }
            $descriptor->validateValue($change->value);
        }
    }

    public function apply(SettingPatch $current, SettingPatch $patch, PolicyScope $scope): SettingPatch
    {
        $this->validate($current, $scope, stored: true);
        $this->validate($patch, $scope);
        $changes = $current->changes;
        foreach ($patch->changes as $key => $change) {
            if ($change->operation === PatchOperation::Inherit) {
                unset($changes[$key]);
            } else {
                $changes[$key] = $change;
            }
        }
        ksort($changes, SORT_STRING);

        return new SettingPatch($patch->schemaVersion, $changes);
    }
}
