<?php

declare(strict_types=1);

namespace Proxyma\Application\Execution;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Proxyma\Contracts\Execution\DestinationResolver;
use Proxyma\Domain\Catalog\Target;
use Proxyma\Domain\Execution\ExecutionContext;
use Proxyma\Domain\Execution\ResolvedUrl;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Infrastructure\Http\DnsResolver;
use Symfony\Component\HttpFoundation\IpUtils;

#[Api(since: '1.0')]
final readonly class DestinationPolicy implements DestinationResolver
{
    /**
     * @param  array<string, array<string, list<string>>>  $privateTargets  Exact target ID, host and IP bindings.
     * @param  list<int>  $allowedPorts
     * @param  array<string, list<string>>  $allowedRedirectOrigins  Optional allowed redirect origins/hosts per target ID.
     */
    public function __construct(
        private DnsResolver $dns,
        private array $privateTargets = [],
        private array $allowedPorts = [80, 443],
        private array $allowedRedirectOrigins = [],
    ) {}

    public function resolve(SafeUrl $url, ExecutionContext $context, int $timeoutMs, ?Target $target = null): ResolvedUrl
    {
        return ResolvedUrl::fromPolicy($this, $url, $context, $timeoutMs, $target);
    }

    /** @return array{non-empty-list<string>, Target} */
    public function validatedAddresses(SafeUrl $url, ExecutionContext $context, int $timeoutMs, ?Target $target): array
    {
        $originAllowed = $target !== null && (
            $target->origin->equals($url->origin)
            || isset($this->privateTargets[$target->id->value][$url->origin->host])
            || in_array($url->origin->value(), $this->allowedRedirectOrigins[$target->id->value] ?? [], true)
            || in_array($url->origin->host, $this->allowedRedirectOrigins[$target->id->value] ?? [], true)
        );
        if ($target === null || $target->isRetired() || ! $originAllowed
            || ! in_array($url->origin->port, $this->allowedPorts, true)) {
            throw new InvalidArgumentException('Destination target or port denied.');
        }
        $host = $url->origin->host;
        $addresses = $this->dns->resolve($host, $timeoutMs);
        if ($addresses === []) {
            throw new InvalidArgumentException('DNS returned no addresses.');
        }
        $validated = [];
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw new InvalidArgumentException('DNS returned an invalid address.');
            }
            $address = (string) inet_ntop((string) inet_pton($address));
            if (! self::isPublic($address) && ! in_array($address, $this->privateTargets[$target->id->value][$host] ?? [], true)) {
                throw new InvalidArgumentException('Destination address denied.');
            }
            if (! in_array($address, $validated, true)) {
                $validated[] = $address;
            }
        }

        return [$validated, $target];
    }

    public function resolveRedirect(string $location, ExecutionContext $context, int $timeoutMs, Target $target): ResolvedUrl
    {
        return $this->resolve(new SafeUrl($location), $context, $timeoutMs, $target);
    }

    private static function isPublic(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && ! IpUtils::checkIp($address, ['0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '::/96', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/23', '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'ff00::/8']);
    }
}
