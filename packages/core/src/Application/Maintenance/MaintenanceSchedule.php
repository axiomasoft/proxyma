<?php

declare(strict_types=1);

namespace Proxyma\Application\Maintenance;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;

final readonly class MaintenanceSchedule
{
    public function __construct(private Repository $config) {}

    public function register(Schedule $schedule): void
    {
        if ($this->config->get('proxyma.scheduler.enabled') !== true) {
            return;
        }
        $limit = $this->config->get('proxyma.maintenance.batch_size');
        $retention = $this->config->get('proxyma.maintenance.retention_seconds');
        if (! is_int($limit) || $limit < 1 || $limit > 64 || ! is_int($retention) || $retention < 1 || $retention > 2147483647) {
            throw new \InvalidArgumentException('Invalid bounded maintenance configuration.');
        }
        $schedule->command('proxyma:reconcile', ['--limit' => $limit])->everyMinute()->withoutOverlapping();
        $schedule->command('proxyma:prune', ['--limit' => $limit, '--retention' => $retention])->daily()->withoutOverlapping();
    }
}
