<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use Packman\Attributes\Api;
use Packman\Panels\Data\Panel\PanelDescriptor;
use Proxyma\Domain\Panels\DefinitionError;
use Proxyma\Domain\Panels\PanelSlot;

/**
 * Immutable Proxyma policy panel definition mapping slots, resolver, and required permissions.
 */
#[Api(since: '1.0')]
final readonly class PanelDefinition
{
    /** @var array<string, PanelSlot> */
    public array $slots;

    /**
     * @param  array<array-key, mixed>  $slots
     * @param  list<string>  $requiredCapabilities
     * @param  array<string, string>  $permissions
     */
    public function __construct(
        public string $id,
        public string $subjectResolver,
        array $slots,
        public array $requiredCapabilities = [],
        public array $permissions = ['view' => 'binding.view', 'update' => 'binding.update', 'execute' => 'binding.execute'],
        public int $version = 1,
    ) {
        PanelSlot::identifier($id);
        PanelSlot::identifier($subjectResolver);
        if ($version < 1 || $slots === [] || array_diff(['view', 'update', 'execute'], array_keys($permissions)) !== []
            || array_diff(array_keys($permissions), ['view', 'update', 'execute']) !== []) {
            throw new DefinitionError('Panel requires slots, a version and all owner permissions.');
        }
        foreach ($permissions as $permission) {
            PanelSlot::identifier($permission);
        }
        $indexed = [];
        foreach ($slots as $slot) {
            if (! $slot instanceof PanelSlot || isset($indexed[$slot->id])) {
                throw new DefinitionError('Duplicate or invalid panel slot.');
            }
            $indexed[$slot->id] = $slot;
        }
        ksort($indexed, SORT_STRING);
        $this->slots = $indexed;
    }

    public function slot(string $id): PanelSlot
    {
        return $this->slots[$id] ?? throw new DefinitionError('Unknown panel slot.');
    }

    public function toPackmanDescriptor(string $owningModule = 'axiomasoft/proxyma', ?string $label = null): PanelDescriptor
    {
        return new PanelDescriptor(
            version: 'panel/1',
            key: $this->id,
            owningModule: $owningModule,
            requiredCapability: $this->requiredCapabilities[0] ?? null,
            supportedActions: array_keys($this->permissions),
            label: $label ?? $this->id,
        );
    }
}
