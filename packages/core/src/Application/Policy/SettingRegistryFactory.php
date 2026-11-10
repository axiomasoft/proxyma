<?php

declare(strict_types=1);

namespace Proxyma\Application\Policy;

use Illuminate\Contracts\Container\Container;
use Proxyma\Contracts\SettingDefinitionProvider;
use Proxyma\Domain\Policy\InvalidSettingPatch;

final readonly class SettingRegistryFactory
{
    public function __construct(private Container $container) {}

    /** @param array<string, mixed> $configuration */
    public function make(array $configuration, array $strategyKeys = []): SettingRegistry
    {
        self::serializable($configuration);
        if (array_diff(array_keys($configuration), ['approved_providers', 'providers']) !== []) {
            throw new InvalidSettingPatch;
        }
        $approved = $configuration['approved_providers'] ?? [];
        $providers = $configuration['providers'] ?? [];
        if (! is_array($approved) || ! is_array($providers) || ! array_is_list($providers) || count(array_unique($providers, SORT_REGULAR)) !== count($providers)) {
            throw new InvalidSettingPatch;
        }
        foreach ($approved as $id => $class) {
            if (! is_string($id) || preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $id) !== 1
                || ! is_string($class) || ! is_subclass_of($class, SettingDefinitionProvider::class)) {
                throw new InvalidSettingPatch;
            }
        }
        $descriptors = CoreSettings::descriptors($strategyKeys);
        foreach ($providers as $id) {
            if (! is_string($id) || ! isset($approved[$id])) {
                throw new InvalidSettingPatch;
            }
            /** @var mixed $provider */
            $provider = $this->container->make($approved[$id]);
            if (! $provider instanceof SettingDefinitionProvider) {
                throw new InvalidSettingPatch;
            }
            array_push($descriptors, ...$provider->settings());
        }

        return new SettingRegistry($descriptors);
    }

    private static function serializable(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                self::serializable($item);
            }

            return;
        }
        if (! is_scalar($value) && $value !== null) {
            throw new InvalidSettingPatch;
        }
    }
}
