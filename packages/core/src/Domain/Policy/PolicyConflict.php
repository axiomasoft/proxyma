<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use RuntimeException;

final class PolicyConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Policy revision or idempotency conflict.');
    }
}
