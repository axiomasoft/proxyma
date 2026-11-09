<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use RuntimeException;

final class PolicyStorageFailure extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Policy storage or audit failed.');
    }
}
