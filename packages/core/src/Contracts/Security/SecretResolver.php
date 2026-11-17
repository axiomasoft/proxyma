<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Security;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Domain\Catalog\CredentialRef;
use Proxyma\Domain\Execution\ResolvedSecret;
use Proxyma\Domain\Policy\TenantKey;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface SecretResolver
{
    public function resolve(CredentialRef $credential, TenantKey $tenant): ?ResolvedSecret;
}
