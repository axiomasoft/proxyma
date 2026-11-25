<?php

declare(strict_types=1);

namespace Proxyma\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Project defaults, panel defaults, and hard upper/lower bounds for policy resolution.
 */
#[Api(since: '1.0')]
final readonly class PolicyOptions implements OptionsFactory
{
    /**
     * @param  array<string, mixed>  $projectDefaults
     * @param  array<string, array<string, mixed>>  $panelDefaults
     * @param  array<string, mixed>  $hardBounds
     */
    public function __construct(
        public array $projectDefaults = [],
        public array $panelDefaults = [],
        public array $hardBounds = [],
        public ?string $panelKey = null,
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'proxyma.policy'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, ['project_defaults', 'panel_defaults', 'hard_bounds', 'panel_key'], $path);

        $projectDefaults = self::readStringMap($validator, $path.'.project_defaults', $config['project_defaults'] ?? []);
        $hardBounds = self::readStringMap($validator, $path.'.hard_bounds', $config['hard_bounds'] ?? []);

        $panelDefaults = [];
        $rawPanelDefaults = $config['panel_defaults'] ?? [];

        if (! is_array($rawPanelDefaults)) {
            $validator->add($path.'.panel_defaults', 'must be an associative array keyed by panel key');
        } else {
            foreach ($rawPanelDefaults as $panel => $values) {
                if (! is_string($panel) || $panel === '' || ! is_array($values)) {
                    $validator->add($path.'.panel_defaults', 'must map non-empty panel keys to associative setting maps');

                    continue;
                }

                $panelDefaults[$panel] = self::readStringMap($validator, $path.'.panel_defaults.'.$panel, $values);
            }
        }

        $panelKey = $config['panel_key'] ?? null;

        if ($panelKey !== null && (! is_string($panelKey) || trim($panelKey) === '')) {
            $validator->add($path.'.panel_key', 'must be null or a non-empty string');
            $panelKey = null;
        }

        $validator->failIfAny();

        return new self($projectDefaults, $panelDefaults, $hardBounds, is_string($panelKey) ? trim($panelKey) : null);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $result = [
            'project_defaults' => $this->projectDefaults,
            'panel_defaults' => $this->panelDefaults,
            'hard_bounds' => $this->hardBounds,
        ];

        if ($this->panelKey !== null) {
            $result['panel_key'] = $this->panelKey;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private static function readStringMap(ConfigurationValidator $validator, string $path, mixed $value): array
    {
        if (! is_array($value)) {
            $validator->add($path, 'must be an associative array of setting keys');

            return [];
        }

        /** @var array<string, mixed> $map */
        $map = [];

        foreach ($value as $key => $entry) {
            if (! is_string($key) || $key === '') {
                $validator->add($path, 'must contain only non-empty string setting keys');

                continue;
            }

            $map[$key] = $entry;
        }

        return $map;
    }
}
