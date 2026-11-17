<?php

declare(strict_types=1);

namespace Proxyma\Application\Diagnostics;

use Closure;
use Packman\Attributes\Api;
use Proxyma\Configuration\ProxymaOptions;
use Proxyma\Contracts\Health\DiagnosticsQueries;
use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Domain\Execution\SchemaDiagnosticReport;
use Proxyma\Infrastructure\Http\TransportDoctor;
use Throwable;

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
        $schema = $this->queries->verifySchemaCapabilities();
        $configuration = true;
        $schedulerEnabled = false;

        try {
            $options = $this->optionsResolver !== null
                ? ($this->optionsResolver)()
                : ($this->options ?? new ProxymaOptions);

            $configuration = strlen($options->wireDigestKey->reveal()) >= 32;
            $schedulerEnabled = $options->schedulerEnabled;
        } catch (Throwable) {
            $configuration = false;
        }

        $protocols = [];

        foreach ([ProxyProtocol::Http, ProxyProtocol::Https, ProxyProtocol::Socks5] as $protocol) {
            try {
                TransportDoctor::assertProtocol($protocol);
                $protocols[$protocol->value] = true;
            } catch (Throwable) {
                $protocols[$protocol->value] = false;
            }
        }

        return [
            'healthy' => $schema->healthy && $configuration && ! in_array(false, $protocols, true),
            'schema' => $schema,
            'configuration_valid' => $configuration,
            'protocols' => $protocols,
            'scheduler_enabled' => $schedulerEnabled,
            'network_io' => false,
            'lease_inflight_is_physical_sockets' => false,
        ];
    }
}
