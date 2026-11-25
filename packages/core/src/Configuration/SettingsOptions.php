<?php

declare(strict_types=1);

namespace Proxyma\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Approved setting definition providers and active provider selections.
 */
#[Api(since: '1.0')]
final readonly class SettingsOptions implements OptionsFactory
{
    /**
     * @param  array<string, string>  $approvedProviders
     * @param  list<string>  $providers
     */
    public function __construct(
        public array $approvedProviders = [],
        public array $providers = [],
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'proxyma.settings'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, ['approved_providers', 'providers'], $path);

        $approved = [];
        $rawApproved = $config['approved_providers'] ?? [];

        if (! is_array($rawApproved)) {
            $validator->add($path.'.approved_providers', 'must be an associative map of provider id => class-string');
        } else {
            foreach ($rawApproved as $id => $class) {
                if (! is_string($id) || $id === '' || ! is_string($class) || $class === '') {
                    $validator->add($path.'.approved_providers', 'must map non-empty string IDs to SettingDefinitionProvider class strings');

                    continue;
                }

                $approved[$id] = $class;
            }
        }

        $providers = [];
        $rawProviders = $config['providers'] ?? [];

        if (! is_array($rawProviders) || ! array_is_list($rawProviders)) {
            $validator->add($path.'.providers', 'must be a list of approved provider identifiers');
        } else {
            foreach ($rawProviders as $id) {
                if (! is_string($id) || $id === '') {
                    $validator->add($path.'.providers', 'must contain only non-empty strings');
                    $providers = [];

                    break;
                }

                $providers[] = $id;
            }

            if (count(array_unique($providers)) !== count($providers)) {
                $validator->add($path.'.providers', 'must not contain duplicate provider identifiers');
            }
        }

        $validator->failIfAny();

        return new self($approved, $providers);
    }

    /** @return array{approved_providers: array<string, string>, providers: list<string>} */
    public function toArray(): array
    {
        return [
            'approved_providers' => $this->approvedProviders,
            'providers' => $this->providers,
        ];
    }
}
