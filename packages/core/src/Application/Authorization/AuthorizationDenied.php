<?php

declare(strict_types=1);

namespace Proxyma\Application\Authorization;

use RuntimeException;

final class AuthorizationDenied extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Catalog access denied.');
    }
}
