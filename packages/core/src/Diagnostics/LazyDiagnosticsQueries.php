<?php

declare(strict_types=1);

namespace Proxyma\Diagnostics;

use Closure;
use Override;
use Packman\Attributes\Internal;
use Proxyma\Contracts\Health\DiagnosticsQueries;
use Proxyma\Domain\Execution\SchemaDiagnosticReport;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\HealthSnapshot;
use Proxyma\Domain\Policy\TenantKey;

/**
 * Defers diagnostic storage until a check runs, using its owning application.
 */
#[Internal]
final readonly class LazyDiagnosticsQueries implements DiagnosticsQueries
{
    /** @param Closure(): DiagnosticsQueries $resolve */
    public function __construct(private Closure $resolve) {}

    #[Override]
    public function verifySchemaCapabilities(): SchemaDiagnosticReport
    {
        return ($this->resolve)()->verifySchemaCapabilities();
    }

    #[Override]
    public function inspectRouteHealth(TenantKey $tenant, Uuid $routeId, ?Uuid $targetId = null): ?HealthSnapshot
    {
        return ($this->resolve)()->inspectRouteHealth($tenant, $routeId, $targetId);
    }

    #[Override]
    public function inspectTargetHealth(TenantKey $tenant, Uuid $targetId): ?HealthSnapshot
    {
        return ($this->resolve)()->inspectTargetHealth($tenant, $targetId);
    }
}
