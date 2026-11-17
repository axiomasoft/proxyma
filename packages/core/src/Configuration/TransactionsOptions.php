<?php

declare(strict_types=1);

namespace Proxyma\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * PostgreSQL transaction timeout and retry bounds.
 */
#[Api(since: '1.0')]
final readonly class TransactionsOptions implements OptionsFactory
{
    public function __construct(
        public string $lockTimeout = '2s',
        public string $statementTimeout = '5s',
        public int $maxAttempts = 3,
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'proxyma.transactions'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, ['lock_timeout', 'statement_timeout', 'max_attempts'], $path);

        $lockTimeout = $config['lock_timeout'] ?? '2s';

        if (! is_string($lockTimeout) || $lockTimeout === '') {
            $validator->add($path.'.lock_timeout', 'must be a non-empty PostgreSQL duration string');
            $lockTimeout = '2s';
        }

        $statementTimeout = $config['statement_timeout'] ?? '5s';

        if (! is_string($statementTimeout) || $statementTimeout === '') {
            $validator->add($path.'.statement_timeout', 'must be a non-empty PostgreSQL duration string');
            $statementTimeout = '5s';
        }

        $maxAttempts = $config['max_attempts'] ?? 3;

        if (! is_int($maxAttempts) || $maxAttempts < 1 || $maxAttempts > 20) {
            $validator->add($path.'.max_attempts', 'must be an integer between 1 and 20');
            $maxAttempts = 3;
        }

        $validator->failIfAny();

        return new self($lockTimeout, $statementTimeout, $maxAttempts);
    }
}
