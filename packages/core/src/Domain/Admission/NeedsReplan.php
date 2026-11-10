<?php

declare(strict_types=1);

namespace Proxyma\Domain\Admission;

use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use RuntimeException;

final class NeedsReplan extends RuntimeException
{
    public bool $released = false;

    public function __construct(public readonly TenantKey $tenant, public readonly Uuid $operationId, public readonly Uuid $permitId)
    {
        parent::__construct('Quota window ended; a new physical attempt is required.');
    }
}
