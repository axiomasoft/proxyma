<?php

declare(strict_types=1);

namespace Proxyma\Application\Diagnostics;

use Illuminate\Contracts\Config\Repository;
use Proxyma\Contracts\DiagnosticsQueries;
use Proxyma\Domain\Admission\QueueLimits;
use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Domain\Health\HealthThresholds;
use Proxyma\Infrastructure\Http\TransportDoctor;

final readonly class Doctor
{
    public function __construct(private DiagnosticsQueries $queries, private Repository $config) {}

    public function inspect(): array
    {
        $schema = $this->queries->verifySchemaCapabilities();
        $configuration = true;
        try {
            new QueueLimits(...$this->config->get('proxyma.queue', []));
            new HealthThresholds(...$this->config->get('proxyma.health', []));
            $configuration = strlen((string) $this->config->get('proxyma.wire.digest_key')) >= 32
                && $this->config->get('proxyma.safety') === ['admission' => 'mandatory', 'budgets' => 'mandatory', 'health' => 'mandatory']
                && is_bool($this->config->get('proxyma.scheduler.enabled'))
                && is_int($this->config->get('proxyma.maintenance.batch_size'))
                && $this->config->get('proxyma.maintenance.batch_size') >= 1 && $this->config->get('proxyma.maintenance.batch_size') <= 64
                && is_int($this->config->get('proxyma.maintenance.retention_seconds')) && $this->config->get('proxyma.maintenance.retention_seconds') >= 1;
        } catch (\Throwable) {
            $configuration = false;
        }
        $protocols = [];
        foreach ([ProxyProtocol::Http, ProxyProtocol::Https, ProxyProtocol::Socks5] as $protocol) {
            try {
                TransportDoctor::assertProtocol($protocol);
                $protocols[$protocol->value] = true;
            } catch (\Throwable) {
                $protocols[$protocol->value] = false;
            }
        }

        return ['healthy' => $schema->healthy && $configuration && ! in_array(false, $protocols, true),
            'schema' => $schema, 'configuration_valid' => $configuration, 'protocols' => $protocols,
            'scheduler_enabled' => $this->config->get('proxyma.scheduler.enabled') === true,
            'network_io' => false, 'lease_inflight_is_physical_sockets' => false];
    }
}
