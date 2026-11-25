<?php

declare(strict_types=1);

namespace Proxyma\Application\Execution;

use Packman\Attributes\Api;
use Proxyma\Domain\Catalog\Endpoint;
use Proxyma\Domain\Execution\ProxyDestination;
use Proxyma\Infrastructure\Http\DnsResolver;
use Proxyma\Infrastructure\Http\TransportDoctor;
use RuntimeException;

#[Api(since: '1.0')]
final readonly class ProxyResolver
{
    public function __construct(private DnsResolver $dns) {}

    public function resolve(Endpoint $endpoint, int $timeoutMs): ProxyDestination
    {
        return ProxyDestination::fromResolver($this, $endpoint, $timeoutMs);
    }

    public function validatedAddress(Endpoint $endpoint, int $timeoutMs): string
    {
        TransportDoctor::assertProtocol($endpoint->protocol);
        $addresses = $this->dns->resolve($endpoint->host, $timeoutMs);
        if ($addresses === []) {
            throw new RuntimeException('Proxy DNS returned no addresses.');
        }
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw new RuntimeException('Proxy DNS returned an invalid address.');
            }
        }

        return $addresses[0];
    }
}
