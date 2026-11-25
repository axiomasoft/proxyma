<?php

declare(strict_types=1);

namespace Proxyma\Diagnostics;

use Closure;
use Illuminate\Contracts\Config\Repository;
use LogicException;
use Override;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Proxyma\Configuration\ProxymaOptions;
use Proxyma\Contracts\Health\DiagnosticsQueries;
use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Domain\Execution\SchemaDiagnosticReport;
use Proxyma\Infrastructure\Http\TransportDoctor;
use Throwable;

/**
 * Packman doctor check verifying Proxyma configuration, schema capabilities, wire key strength, and cURL protocols.
 */
#[Api(since: '1.0')]
final readonly class ProxymaDoctorCheck implements DoctorCheck
{
    /** @param (Closure(): ProxymaOptions)|null $optionsResolver */
    public function __construct(
        private ?Repository $config,
        private DiagnosticsQueries $queries,
        private ?ProxymaOptions $options = null,
        private ?Closure $optionsResolver = null,
    ) {}

    #[Override]
    public function key(): string
    {
        return 'proxyma.runtime.configuration';
    }

    #[Override]
    public function title(): string
    {
        return 'Proxyma schema, wire key, and transport capabilities';
    }

    /** @return list<DoctorFinding> */
    #[Override]
    public function run(DoctorContext $context): array
    {
        return $this->diagnose()['findings'];
    }

    /**
     * @internal Authoritative diagnostic pass shared with {@see Doctor}.
     *
     * @return array{
     *     findings: list<DoctorFinding>,
     *     schema: ?SchemaDiagnosticReport,
     *     configuration_valid: bool,
     *     protocols: array<string, bool>,
     *     scheduler_enabled: bool,
     * }
     */
    #[Internal]
    public function diagnose(): array
    {
        $findings = [];
        $protocols = [
            ProxyProtocol::Http->value => true,
            ProxyProtocol::Https->value => true,
            ProxyProtocol::Socks5->value => true,
        ];

        foreach ([ProxyProtocol::Http, ProxyProtocol::Https, ProxyProtocol::Socks5] as $protocol) {
            try {
                TransportDoctor::assertProtocol($protocol);
            } catch (LogicException) {
                $protocols[$protocol->value] = false;
                $findings[] = DoctorFinding::error(
                    code: 'proxyma.doctor.unsupported_protocol',
                    message: sprintf('Configured PHP cURL extension lacks support for proxy protocol "%s".', $protocol->value),
                    remediation: 'Install PHP cURL compiled with support for HTTP, HTTPS, and SOCKS5 proxies.',
                    evidence: ['protocol' => $protocol->value],
                );
            }
        }

        [$options, $configFinding] = $this->resolveOptions();
        $schema = null;

        try {
            $schema = $this->queries->verifySchemaCapabilities();

            if (! $schema->healthy) {
                $findings[] = DoctorFinding::error(
                    code: 'proxyma.doctor.schema_unhealthy',
                    message: 'Proxyma database schema capabilities are incomplete.',
                    remediation: 'Run the proxyma central PostgreSQL schema set migrations.',
                    evidence: [
                        'missing_tables' => count($schema->missingTables),
                        'missing_constraints' => count($schema->missingConstraints),
                    ],
                );
            }
        } catch (Throwable) {
            $findings[] = DoctorFinding::error(
                code: 'proxyma.doctor.schema_unreachable',
                message: 'Unable to verify Proxyma database schema capabilities.',
                remediation: 'Ensure the configured PostgreSQL connection is reachable and migrations have been applied.',
                evidence: $options instanceof ProxymaOptions ? ['connection' => $options->connection] : [],
            );
        }

        if ($configFinding !== null) {
            $findings[] = $configFinding;

            return [
                'findings' => $findings,
                'schema' => $schema,
                'configuration_valid' => false,
                'protocols' => $protocols,
                'scheduler_enabled' => false,
            ];
        }

        if (! $options instanceof ProxymaOptions) {
            return [
                'findings' => $findings,
                'schema' => $schema,
                'configuration_valid' => false,
                'protocols' => $protocols,
                'scheduler_enabled' => false,
            ];
        }

        $configurationValid = true;

        if ($this->config !== null && ! is_array($this->config->get('database.connections.'.$options->connection))) {
            $findings[] = DoctorFinding::error(
                code: 'proxyma.doctor.missing_connection',
                message: sprintf('Configured Proxyma database connection "%s" is not defined in database.connections.', $options->connection),
                remediation: 'Define the named PostgreSQL connection in config/database.php or update proxyma.connection.',
                evidence: ['connection' => $options->connection],
            );
        }

        if (strlen($options->wireDigestKey->reveal()) < 32) {
            $configurationValid = false;
            $findings[] = DoctorFinding::error(
                code: 'proxyma.doctor.weak_wire_digest_key',
                message: 'Configured proxyma.wire.digest_key must be at least 32 bytes for request sealing.',
                remediation: 'Set PROXYMA_WIRE_DIGEST_KEY to a random secret of at least 32 characters.',
            );
        }

        if ($findings === []) {
            $findings = [
                DoctorFinding::ok(
                    code: 'proxyma.doctor.healthy',
                    message: 'Proxyma options, wire key, cURL proxy protocols, and PostgreSQL schema capabilities are healthy.',
                    evidence: [
                        'connection' => $options->connection,
                        'table_prefix' => $options->tablePrefix->value(),
                        'scheduler_enabled' => $options->schedulerEnabled,
                    ],
                ),
            ];
        }

        return [
            'findings' => $findings,
            'schema' => $schema,
            'configuration_valid' => $configurationValid,
            'protocols' => $protocols,
            'scheduler_enabled' => $options->schedulerEnabled,
        ];
    }

    /** @return array{0: ?ProxymaOptions, 1: ?DoctorFinding} */
    private function resolveOptions(): array
    {
        if ($this->options !== null) {
            return [$this->options, null];
        }

        if ($this->optionsResolver !== null) {
            try {
                return [($this->optionsResolver)(), null];
            } catch (Throwable) {
                return [
                    null,
                    DoctorFinding::error(
                        code: 'proxyma.doctor.invalid_configuration',
                        message: 'Configured Proxyma options failed schema validation.',
                        remediation: 'Fix invalid entries under config/proxyma.php.',
                    ),
                ];
            }
        }

        return [
            null,
            DoctorFinding::error(
                code: 'proxyma.doctor.options_unbound',
                message: 'ProxymaOptions could not be resolved from the application container.',
                remediation: 'Ensure ProxymaServiceProvider is registered.',
            ),
        ];
    }
}
