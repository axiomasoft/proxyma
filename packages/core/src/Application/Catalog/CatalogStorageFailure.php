<?php

declare(strict_types=1);

namespace Proxyma\Application\Catalog;

use RuntimeException;

final class CatalogStorageFailure extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Catalog storage or audit failed.');
    }
}
