<?php

declare(strict_types=1);

namespace Proxyma\Configuration;

use InvalidArgumentException;
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
    private const string PG_DURATION_PATTERN = '/^(?:0|[1-9][0-9]{0,8})(?:us|ms|s|min|h|d)?$/D';

    public function __construct(
        public string $lockTimeout = '2s',
        public string $statementTimeout = '5s',
        public int $maxAttempts = 3,
    ) {
        if (! self::isValidDuration($lockTimeout)) {
            throw new InvalidArgumentException('Invalid lock_timeout PostgreSQL duration.');
        }

        if (! self::isValidDuration($statementTimeout)) {
            throw new InvalidArgumentException('Invalid statement_timeout PostgreSQL duration.');
        }

        if ($maxAttempts < 1 || $maxAttempts > 20) {
            throw new InvalidArgumentException('max_attempts must be between 1 and 20.');
        }
    }

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'proxyma.transactions'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, ['lock_timeout', 'statement_timeout', 'max_attempts'], $path);

        $lockTimeout = $config['lock_timeout'] ?? '2s';

        if (! is_string($lockTimeout) || ! self::isValidDuration($lockTimeout)) {
            $validator->add($path.'.lock_timeout', 'must be a non-empty PostgreSQL duration string (e.g. 2s, 500ms)');
            $lockTimeout = '2s';
        }

        $statementTimeout = $config['statement_timeout'] ?? '5s';

        if (! is_string($statementTimeout) || ! self::isValidDuration($statementTimeout)) {
            $validator->add($path.'.statement_timeout', 'must be a non-empty PostgreSQL duration string (e.g. 5s, 2000ms)');
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

    private static function isValidDuration(string $value): bool
    {
        return preg_match(self::PG_DURATION_PATTERN, $value) === 1;
    }
}
