<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Telemetry;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Proxyma\Domain\Execution\Uuid;
use Psr\Log\LoggerInterface;

/** Конечные имена/labels; произвольный context никогда не попадает в exporter. */
#[Api(since: '1.0')]
final readonly class OperationsTelemetry
{
    public const array METRICS = ['queue_age_seconds', 'admission_wait_seconds', 'blocked', 'claim', 'completed', 'unknown', 'reconcile_lag_seconds', 'lease_inflight'];

    public const array REASONS = ['none', 'health', 'quota', 'budget', 'configuration', 'permission', 'other'];

    public function __construct(private LoggerInterface $logger) {}

    /** @return array{name: string, value: float, labels: array{reason: string}} */
    public function sample(string $metric, float $value, string $reason = 'none'): array
    {
        if (! in_array($metric, self::METRICS, true) || ! is_finite($value) || $value < 0) {
            throw new InvalidArgumentException('Invalid operations metric.');
        }

        return ['name' => 'proxyma_'.$metric, 'value' => $value, 'labels' => ['reason' => in_array($reason, self::REASONS, true) ? $reason : 'other']];
    }

    /** @param array<string, mixed> $context */
    public function event(string $event, ?Uuid $correlation = null, string $reason = 'none', array $context = []): void
    {
        $this->logger->info('proxyma.operations', [
            'event' => in_array($event, ['diagnose', 'probe', 'reconcile', 'prune', 'reset', 'blocked'], true) ? $event : 'other',
            'correlation_id' => $correlation?->value,
            'reason' => in_array($reason, self::REASONS, true) ? $reason : 'other',
        ]);
    }

    public function blocked(string $reason, ?Uuid $correlation = null): void
    {
        $category = match (true) {
            str_contains($reason, 'quota') => 'quota',
            str_contains($reason, 'health'), str_contains($reason, 'credential'), str_contains($reason, 'probe') => 'health',
            str_contains($reason, 'tenant'), str_contains($reason, 'permission') => 'permission',
            str_contains($reason, 'capacity'), str_contains($reason, 'rate'), str_contains($reason, 'budget') => 'budget',
            str_contains($reason, 'configuration'), str_contains($reason, 'profile') => 'configuration',
            default => 'other',
        };
        $this->logger->info('proxyma.metric', $this->sample('blocked', 1, $category));
        $this->event('blocked', $correlation, $category);
    }
}
