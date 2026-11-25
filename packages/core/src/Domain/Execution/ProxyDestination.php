<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;
use LogicException;
use Packman\Attributes\Api;
use Proxyma\Application\Execution\ProxyResolver;
use Proxyma\Domain\Catalog\Endpoint;
use Proxyma\Domain\Catalog\ProxyProtocol;

/** Endpoint identity and a single locally resolved address, prepared before admission. */
#[Api(since: '1.0')]
final readonly class ProxyDestination
{
    private function __construct(public Endpoint $endpoint, public string $address)
    {
        if ($endpoint->isRetired() || $endpoint->protocol === ProxyProtocol::Socks5h
            || filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('Proxy requires a live endpoint and a locally resolved IP; SOCKS5h is forbidden.');
        }
    }

    public static function fromResolver(ProxyResolver $resolver, Endpoint $endpoint, int $timeoutMs): self
    {
        return new self($endpoint, $resolver->validatedAddress($endpoint, $timeoutMs));
    }

    public function __serialize(): array
    {
        throw new LogicException('ProxyDestination cannot be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('ProxyDestination cannot be reconstructed.');
    }
}
