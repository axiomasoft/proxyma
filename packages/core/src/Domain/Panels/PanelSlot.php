<?php

declare(strict_types=1);

namespace Proxyma\Domain\Panels;

use Packman\Attributes\Api;
use Packman\Panels\Data\Panel\PanelSlot as PackmanPanelSlot;
use Proxyma\Domain\Policy\PatchOperation;
use Proxyma\Domain\Policy\SettingChange;
use Proxyma\Domain\Policy\SettingPatch;

/**
 * Declarative slot inside a Proxyma policy panel with ordered setting keys and optional defaults.
 */
#[Api(since: '1.0')]
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

    public function toPackmanSlot(): PackmanPanelSlot
    {
        $defaults = [];

        foreach ($this->defaults->changes as $key => $change) {
            $defaults[$key] = $change->toArray();
        }

        /** @var list<string> $settings */
        $settings = array_values($this->settings);

        return new PackmanPanelSlot(
            id: $this->id,
            settings: $settings,
            defaults: $defaults,
            inheritDefaults: $this->inheritDefaults,
        );
    }

    public static function fromPackmanSlot(PackmanPanelSlot $slot): self
    {
        $changes = [];

        foreach ($slot->defaults as $key => $entry) {
            if (! is_array($entry) || ! isset($entry['op'])) {
                $changes[$key] = SettingChange::set($entry);

                continue;
            }

            $changes[$key] = match ($entry['op']) {
                PatchOperation::Set->value => SettingChange::set($entry['value'] ?? null),
                PatchOperation::Inherit->value => SettingChange::inherit(),
                PatchOperation::Disable->value => SettingChange::disable(),
                default => SettingChange::set($entry['value'] ?? null),
            };
        }

        return new self(
            id: $slot->id,
            settings: $slot->settings,
            defaults: new SettingPatch(1, $changes),
            inheritDefaults: $slot->inheritDefaults,
        );
    }
}
