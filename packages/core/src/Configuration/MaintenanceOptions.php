<?php

declare(strict_types=1);

namespace Proxyma\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Bounded reconciliation and pruning batch configuration.
 */
#[Api(since: '1.0')]
final readonly class MaintenanceOptions implements OptionsFactory
{
    public function __construct(
        public int $batchSize = 32,
        public int $retentionSeconds = 2592000,
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'proxyma.maintenance'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, ['batch_size', 'retention_seconds'], $path);

        $batchSize = $config['batch_size'] ?? 32;

        if (! is_int($batchSize) || $batchSize < 1 || $batchSize > 64) {
            $validator->add($path.'.batch_size', 'must be an integer between 1 and 64');
            $batchSize = 32;
        }

        $retention = $config['retention_seconds'] ?? 2592000;

        if (! is_int($retention) || $retention < 1 || $retention > 2147483647) {
            $validator->add($path.'.retention_seconds', 'must be an integer between 1 and 2147483647');
            $retention = 2592000;
        }

        $validator->failIfAny();

        return new self($batchSize, $retention);
    }
}
