<?php

declare(strict_types=1);

namespace Proxyma\Configuration;

use InvalidArgumentException;
use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;
use Proxyma\Domain\Admission\QueueLimits;

/**
 * Bounded admission queue configuration compiled into {@see QueueLimits}.
 */
#[Api(since: '1.0')]
final readonly class QueueOptions implements OptionsFactory
{
    public function __construct(
        public int $maxPendingPerScope = 64,
        public int $ticketTtlMs = 30000,
        public int $maxEligibleSkip = 8,
        public int $retryMs = 100,
        public int $maxOperationMs = 3600000,
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'proxyma.queue'): self
    {
        $validator = new ConfigurationValidator;
        $allowed = ['maxPendingPerScope', 'ticketTtlMs', 'maxEligibleSkip', 'retryMs', 'maxOperationMs'];
        $validator->rejectUnknownKeys($config, $allowed, $path);

        $maxPending = self::requireInt($validator, $path.'.maxPendingPerScope', $config['maxPendingPerScope'] ?? 64, 1, 64);
        $ticketTtl = self::requireInt($validator, $path.'.ticketTtlMs', $config['ticketTtlMs'] ?? 30000, 1, 60000);
        $maxEligibleSkip = self::requireInt($validator, $path.'.maxEligibleSkip', $config['maxEligibleSkip'] ?? 8, 0, 64);
        $retryMs = self::requireInt($validator, $path.'.retryMs', $config['retryMs'] ?? 100, 1, 1000);
        $maxOperationMs = self::requireInt($validator, $path.'.maxOperationMs', $config['maxOperationMs'] ?? 3600000, 1, 3600000);

        $validator->failIfAny();

        try {
            new QueueLimits($maxPending, $ticketTtl, $maxEligibleSkip, $retryMs, $maxOperationMs);
        } catch (InvalidArgumentException $exception) {
            $validator->add($path, $exception->getMessage());
            $validator->failIfAny();
        }

        return new self($maxPending, $ticketTtl, $maxEligibleSkip, $retryMs, $maxOperationMs);
    }

    public function toQueueLimits(): QueueLimits
    {
        return new QueueLimits(
            $this->maxPendingPerScope,
            $this->ticketTtlMs,
            $this->maxEligibleSkip,
            $this->retryMs,
            $this->maxOperationMs,
        );
    }

    private static function requireInt(
        ConfigurationValidator $validator,
        string $path,
        mixed $value,
        int $min,
        int $max,
    ): int {
        if (! is_int($value) || $value < $min || $value > $max) {
            $validator->add($path, sprintf('must be an integer between %d and %d', $min, $max));

            return $min;
        }

        return $value;
    }
}
