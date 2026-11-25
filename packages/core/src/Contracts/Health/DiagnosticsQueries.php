<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Health;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Proxyma\Domain\Execution\SchemaDiagnosticReport;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\HealthSnapshot;
use Proxyma\Domain\Policy\TenantKey;

#[Spi(kind: SpiKind::Call, since: '1.0')]
interface DiagnosticsQueries
{
    public function verifySchemaCapabilities(): SchemaDiagnosticReport;

    public function inspectRouteHealth(TenantKey $tenant, Uuid $routeId, ?Uuid $targetId = null): ?HealthSnapshot;

    public function inspectTargetHealth(TenantKey $tenant, Uuid $targetId): ?HealthSnapshot;
}
