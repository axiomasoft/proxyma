<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Secrets;

use RuntimeException;

final class SecretStorageFailure extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Credential storage or decryption failed.');
    }
}
