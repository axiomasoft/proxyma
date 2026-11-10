<?php

declare(strict_types=1);

namespace Proxyma\Domain\Admission;

use DateTimeImmutable;
use Proxyma\Domain\Execution\RetryAt;

final readonly class FifoPolicy
{
    public function __construct(private QueueLimits $limits) {}

    /**
     * @param  list<array{sequence: int, id: string, ready: DateTimeImmutable, expires: DateTimeImmutable}>  $predecessors
     */
    public function wait(DateTimeImmutable $now, DateTimeImmutable $ready, DateTimeImmutable $expires, array $predecessors): ?DemandWait
    {
        if ($ready > $now) {
            return new DemandWait('budget_wait', new RetryAt(min($ready, $expires)));
        }
        usort($predecessors, static fn ($a, $b): int => ($a['sequence'] <=> $b['sequence']) ?: strcmp($a['id'], $b['id']));
        $skipped = 0;
        foreach ($predecessors as $older) {
            if ($older['expires'] <= $now) {
                continue;
            }
            if ($older['ready'] <= $now) {
                return new DemandWait('fifo_predecessor', new RetryAt(min($expires, $older['expires'], $now->modify('+'.$this->limits->retryMs.' milliseconds'))));
            }
            if (++$skipped > $this->limits->maxEligibleSkip) {
                return new DemandWait('fifo_skip_bound', new RetryAt(min($expires, $older['expires'], $older['ready'])));
            }
        }

        return null;
    }
}
