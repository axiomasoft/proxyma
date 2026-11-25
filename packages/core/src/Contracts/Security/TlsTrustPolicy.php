<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Security;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Execution\TlsTrustConfiguration;
use Proxyma\Domain\Execution\Uuid;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface TlsTrustPolicy
{
    public function tlsConfigurationFor(CanonicalOrigin $origin, Uuid $targetId): TlsTrustConfiguration;

    public function proxyConfigurationFor(string $originalHost): TlsTrustConfiguration;
}
