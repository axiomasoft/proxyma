<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Proxyma\Domain\Catalog\CredentialRef;
use Proxyma\Domain\Execution\ResolvedSecret;
use Proxyma\Domain\Policy\TenantKey;

interface SecretResolver
{
    public function resolve(CredentialRef $credential, TenantKey $tenant): ?ResolvedSecret;
}
