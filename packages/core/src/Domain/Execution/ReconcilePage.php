<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ReconcilePage
{
    public function __construct(public int $scanned, public int $locked, public int $changed, public ?string $cursor, public bool $busy = false) {}
}
