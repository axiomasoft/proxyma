<?php

declare(strict_types=1);

namespace Proxyma\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Mandatory durable safety modes (admission, budgets, health) for Proxyma.
 */
#[Api(since: '1.0')]
final readonly class SafetyOptions implements OptionsFactory
{
    public function __construct(
        public string $admission = 'mandatory',
        public string $budgets = 'mandatory',
        public string $health = 'mandatory',
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'proxyma.safety'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, ['admission', 'budgets', 'health'], $path);

        $admission = self::requireMandatory($validator, $path.'.admission', $config['admission'] ?? 'mandatory');
        $budgets = self::requireMandatory($validator, $path.'.budgets', $config['budgets'] ?? 'mandatory');
        $health = self::requireMandatory($validator, $path.'.health', $config['health'] ?? 'mandatory');

        $validator->failIfAny();

        return new self($admission, $budgets, $health);
    }

    /** @return array{admission: string, budgets: string, health: string} */
    public function toArray(): array
    {
        return [
            'admission' => $this->admission,
            'budgets' => $this->budgets,
            'health' => $this->health,
        ];
    }

    private static function requireMandatory(ConfigurationValidator $validator, string $path, mixed $value): string
    {
        if ($value !== 'mandatory') {
            $validator->add($path, 'must equal "mandatory" (safety bypass is forbidden)');

            return 'mandatory';
        }

        return $value;
    }
}
