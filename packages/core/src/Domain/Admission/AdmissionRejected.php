<?php

declare(strict_types=1);

namespace Proxyma\Domain\Admission;

use RuntimeException;

final class AdmissionRejected extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Joint admission rejected: {$reason}");
    }
}
