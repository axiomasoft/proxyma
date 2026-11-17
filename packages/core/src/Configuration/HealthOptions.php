<?php

declare(strict_types=1);

namespace Proxyma\Configuration;

use InvalidArgumentException;
use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;
use Proxyma\Domain\Health\HealthThresholds;

/**
 * Compiled circuit-breaker and exponential cooldown thresholds mapped to {@see HealthThresholds}.
 */
#[Api(since: '1.0')]
final readonly class HealthOptions implements OptionsFactory
{
    public function __construct(
        public int $globalConsecutive = 5,
        public int $globalBaseSeconds = 1800,
        public int $globalCapSeconds = 21600,
        public int $routeTargetConsecutive = 5,
        public int $routeTargetMinSample = 10,
        public int $routeTargetFailurePercent = 60,
        public int $routeTargetWindowSeconds = 600,
        public int $routeTargetBaseSeconds = 300,
        public int $routeTargetCapSeconds = 3600,
        public int $stableDecaySeconds = 600,
        public int $corroborationWindowSeconds = 300,
        public int $originCooldownSeconds = 900,
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'proxyma.health'): self
    {
        $validator = new ConfigurationValidator;
        $defaults = [
            'globalConsecutive' => 5,
            'globalBaseSeconds' => 1800,
            'globalCapSeconds' => 21600,
            'routeTargetConsecutive' => 5,
            'routeTargetMinSample' => 10,
            'routeTargetFailurePercent' => 60,
            'routeTargetWindowSeconds' => 600,
            'routeTargetBaseSeconds' => 300,
            'routeTargetCapSeconds' => 3600,
            'stableDecaySeconds' => 600,
            'corroborationWindowSeconds' => 300,
            'originCooldownSeconds' => 900,
        ];
        $validator->rejectUnknownKeys($config, array_keys($defaults), $path);

        /** @var array<string, int> $resolved */
        $resolved = [];

        foreach ($defaults as $key => $default) {
            $value = $config[$key] ?? $default;

            if (! is_int($value) || $value < 1) {
                $validator->add($path.'.'.$key, 'must be a positive integer');
                $resolved[$key] = $default;

                continue;
            }

            $resolved[$key] = $value;
        }

        $validator->failIfAny();

        try {
            new HealthThresholds(
                globalConsecutive: $resolved['globalConsecutive'],
                globalBaseSeconds: $resolved['globalBaseSeconds'],
                globalCapSeconds: $resolved['globalCapSeconds'],
                routeTargetConsecutive: $resolved['routeTargetConsecutive'],
                routeTargetMinSample: $resolved['routeTargetMinSample'],
                routeTargetFailurePercent: $resolved['routeTargetFailurePercent'],
                routeTargetWindowSeconds: $resolved['routeTargetWindowSeconds'],
                routeTargetBaseSeconds: $resolved['routeTargetBaseSeconds'],
                routeTargetCapSeconds: $resolved['routeTargetCapSeconds'],
                stableDecaySeconds: $resolved['stableDecaySeconds'],
                corroborationWindowSeconds: $resolved['corroborationWindowSeconds'],
                originCooldownSeconds: $resolved['originCooldownSeconds'],
            );
        } catch (InvalidArgumentException $exception) {
            $validator->add($path, $exception->getMessage());
            $validator->failIfAny();
        }

        return new self(
            globalConsecutive: $resolved['globalConsecutive'],
            globalBaseSeconds: $resolved['globalBaseSeconds'],
            globalCapSeconds: $resolved['globalCapSeconds'],
            routeTargetConsecutive: $resolved['routeTargetConsecutive'],
            routeTargetMinSample: $resolved['routeTargetMinSample'],
            routeTargetFailurePercent: $resolved['routeTargetFailurePercent'],
            routeTargetWindowSeconds: $resolved['routeTargetWindowSeconds'],
            routeTargetBaseSeconds: $resolved['routeTargetBaseSeconds'],
            routeTargetCapSeconds: $resolved['routeTargetCapSeconds'],
            stableDecaySeconds: $resolved['stableDecaySeconds'],
            corroborationWindowSeconds: $resolved['corroborationWindowSeconds'],
            originCooldownSeconds: $resolved['originCooldownSeconds'],
        );
    }

    public function toHealthThresholds(): HealthThresholds
    {
        return new HealthThresholds(
            globalConsecutive: $this->globalConsecutive,
            globalBaseSeconds: $this->globalBaseSeconds,
            globalCapSeconds: $this->globalCapSeconds,
            routeTargetConsecutive: $this->routeTargetConsecutive,
            routeTargetMinSample: $this->routeTargetMinSample,
            routeTargetFailurePercent: $this->routeTargetFailurePercent,
            routeTargetWindowSeconds: $this->routeTargetWindowSeconds,
            routeTargetBaseSeconds: $this->routeTargetBaseSeconds,
            routeTargetCapSeconds: $this->routeTargetCapSeconds,
            stableDecaySeconds: $this->stableDecaySeconds,
            corroborationWindowSeconds: $this->corroborationWindowSeconds,
            originCooldownSeconds: $this->originCooldownSeconds,
        );
    }
}
