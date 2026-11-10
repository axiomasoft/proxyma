<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

final readonly class ReconcilePage
{
    public function __construct(public int $scanned, public int $locked, public int $changed, public ?string $cursor, public bool $busy = false) {}
}
