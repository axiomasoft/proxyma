<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

final readonly class SettingChange
{
    private function __construct(public PatchOperation $operation, public mixed $value = null) {}

    public static function set(mixed $value): self
    {
        return new self(PatchOperation::Set, $value);
    }

    public static function inherit(): self
    {
        return new self(PatchOperation::Inherit);
    }

    public static function disable(): self
    {
        return new self(PatchOperation::Disable);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->operation === PatchOperation::Set ? ['op' => 'set', 'value' => $this->value] : ['op' => $this->operation->value];
    }
}
