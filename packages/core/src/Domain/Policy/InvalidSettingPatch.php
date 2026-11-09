<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use InvalidArgumentException;

final class InvalidSettingPatch extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('Invalid policy patch or setting descriptor.');
    }
}
