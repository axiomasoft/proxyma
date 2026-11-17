<?php

declare(strict_types=1);

namespace Proxyma\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * CA bundle file path maps for upstream origin and proxy TLS verification.
 */
#[Api(since: '1.0')]
final readonly class TlsOptions implements OptionsFactory
{
    /**
     * @param  array<string, string>  $originBundles
     * @param  array<string, string>  $proxyBundles
     */
    public function __construct(
        public array $originBundles = [],
        public array $proxyBundles = [],
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'proxyma.tls'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, ['origin_bundles', 'proxy_bundles'], $path);

        $originBundles = self::readBundleMap($validator, $path.'.origin_bundles', $config['origin_bundles'] ?? []);
        $proxyBundles = self::readBundleMap($validator, $path.'.proxy_bundles', $config['proxy_bundles'] ?? []);

        $validator->failIfAny();

        return new self($originBundles, $proxyBundles);
    }

    /** @return array<string, string> */
    private static function readBundleMap(ConfigurationValidator $validator, string $path, mixed $value): array
    {
        if (! is_array($value)) {
            $validator->add($path, 'must be a map of host patterns to CA bundle paths');

            return [];
        }

        $bundles = [];

        foreach ($value as $host => $caPath) {
            if (! is_string($host) || $host === '' || ! is_string($caPath) || $caPath === '') {
                $validator->add($path, 'bundle entries require non-empty string host keys and CA file paths');

                continue;
            }

            $bundles[$host] = $caPath;
        }

        return $bundles;
    }
}
