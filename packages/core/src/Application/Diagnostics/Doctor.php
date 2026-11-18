<?php

declare(strict_types=1);

namespace Proxyma\Application\Diagnostics;

use Closure;
use Packman\Attributes\Api;
use Packman\Doctor\Data\DoctorFinding;
use Packman\Doctor\Enums\CheckStatus;
use Proxyma\Configuration\ProxymaOptions;
use Proxyma\Contracts\Health\DiagnosticsQueries;
use Proxyma\Diagnostics\ProxymaDoctorCheck;
use Proxyma\Domain\Execution\SchemaDiagnosticReport;

/**
 * Inspects schema capabilities, compiled configuration invariants, and local cURL transport support.
 */
#[Api(since: '1.0')]
final readonly class Doctor
{
    /** @param (Closure(): ProxymaOptions)|null $optionsResolver */
    public function __construct(
        private DiagnosticsQueries $queries,
        private ?ProxymaOptions $options = null,
        private ?Closure $optionsResolver = null,
        private ?ProxymaDoctorCheck $check = null,
    ) {}

    /**
     * @return array{
     *     healthy: bool,
     *     schema: SchemaDiagnosticReport,
     *     configuration_valid: bool,
     *     protocols: array<string, bool>,
     *     scheduler_enabled: bool,
     *     network_io: false,
     *     lease_inflight_is_physical_sockets: false,
     * }
     */
    public function inspect(): array
    {
        $check = $this->check ?? new ProxymaDoctorCheck(
            config: null,
            queries: $this->queries,
            options: $this->optionsResolver === null ? ($this->options ?? new ProxymaOptions) : null,
            optionsResolver: $this->optionsResolver,
        );

        $diagnosis = $check->diagnose();
        $schema = $diagnosis['schema'] ?? new SchemaDiagnosticReport(false, 'unknown');
        $hasErrors = array_any(
            $diagnosis['findings'],
            static fn (DoctorFinding $finding): bool => $finding->status === CheckStatus::Error,
        );

        return [
            'healthy' => ! $hasErrors && $schema->healthy,
            'schema' => $schema,
            'configuration_valid' => $diagnosis['configuration_valid'],
            'protocols' => $diagnosis['protocols'],
            'scheduler_enabled' => $diagnosis['scheduler_enabled'],
            'network_io' => false,
            'lease_inflight_is_physical_sockets' => false,
        ];
    }
}
