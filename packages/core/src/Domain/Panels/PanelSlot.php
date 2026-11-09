<?php

declare(strict_types=1);

namespace Proxyma\Domain\Panels;

use Proxyma\Domain\Policy\SettingPatch;

final readonly class PanelSlot
{
    /** @param array<array-key, string> $settings */
    public function __construct(
        public string $id,
        public array $settings,
        public SettingPatch $defaults = new SettingPatch(1, []),
        public bool $inheritDefaults = true,
    ) {
        self::identifier($id);
        if (! array_is_list($settings) || count(array_unique($settings)) !== count($settings)) {
            throw new DefinitionError('Slot settings must be unique IDs.');
        }
    }

    public static function identifier(string $id): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D', $id) !== 1) {
            throw new DefinitionError('Invalid definition ID.');
        }
    }
}
