<?php

declare(strict_types=1);

namespace Proxyma\Application\Maintenance;

use Illuminate\Console\Scheduling\Schedule;
use InvalidArgumentException;
use Packman\Attributes\Api;
use Proxyma\Configuration\ProxymaOptions;

/**
 * Registers bounded Proxyma reconcile and retention pruning commands on the Laravel scheduler.
 */
#[Api(since: '1.0')]
final readonly class MaintenanceSchedule
{
    public function __construct(private ProxymaOptions $options) {}

    public function register(Schedule $schedule): void
    {
        if (! $this->options->schedulerEnabled) {
            return;
        }

        $limit = $this->options->maintenance->batchSize;
        $retention = $this->options->maintenance->retentionSeconds;

        if ($limit < 1 || $limit > 64 || $retention < 1 || $retention > 2147483647) {
            throw new InvalidArgumentException('Invalid bounded maintenance configuration.');
        }

        $schedule->command('proxyma:reconcile', ['--limit' => $limit])->everyMinute()->withoutOverlapping();
        $schedule->command('proxyma:prune', ['--limit' => $limit, '--retention' => $retention])->daily()->withoutOverlapping();
    }
}
