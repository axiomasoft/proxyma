<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Proxyma\Application\Execution\DestinationPolicy;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\Target;
use WeakMap;

#[Api(since: '1.0')]
final class ResolvedUrl
{
    /** @var WeakMap<self, bool>|null */
    private static ?WeakMap $verified = null;

    /** @var non-empty-list<string> */
    public readonly array $addresses;

    public readonly string $originalHost;

    public readonly int $port;

    /**
     * @param  list<string>  $addresses
     */
    public function __construct(
        public readonly SafeUrl $url,
        public readonly string $address,
        array $addresses = [],
        ?string $originalHost = null,
        ?int $port = null,
        public readonly ?Uuid $targetId = null,
        public readonly int $targetRevision = 1,
        public readonly ?string $destinationPolicyKey = null,
        bool $policyVerified = false,
        public readonly ?CanonicalOrigin $targetOrigin = null,
    ) {
        if ($policyVerified) {
            throw new InvalidArgumentException('Destination policy verification cannot be supplied by the caller.');
        }
        $this->originalHost = $originalHost ?? $url->origin->host;
        $this->port = $port ?? $url->origin->port;
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('Expected a valid IP address.');
        }

        $normalized = [$address];
        foreach ($addresses as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                throw new InvalidArgumentException('Expected a list of valid IP addresses.');
            }
            if (! in_array($candidate, $normalized, true)) {
                $normalized[] = $candidate;
            }
        }

        $this->addresses = $normalized;
    }

    public static function fromPolicy(DestinationPolicy $policy, SafeUrl $url, ExecutionContext $context, int $timeoutMs, ?Target $target): self
    {
        [$addresses, $target] = $policy->validatedAddresses($url, $context, $timeoutMs, $target);
        $resolved = new self($url, $addresses[0], $addresses, $url->origin->host, $url->origin->port, $target->id, $target->revision, $target->destinationPolicyKey,
            targetOrigin: $target->origin);
        self::$verified ??= new WeakMap;
        self::$verified[$resolved] = true;

        return $resolved;
    }

    public static function isUrlVerifiedForTarget(Uuid $targetId, SafeUrl $url, int $targetRevision, CanonicalOrigin $targetOrigin): bool
    {
        if (self::$verified === null) {
            return false;
        }

        foreach (self::$verified as $resolved => $verified) {
            if ($verified && $resolved->url === $url && $resolved->targetId?->equals($targetId) === true
                && $resolved->targetRevision === $targetRevision && $resolved->targetOrigin?->equals($targetOrigin) === true) {
                return true;
            }
        }

        return false;
    }

    public function isPolicyVerified(): bool
    {
        return self::$verified !== null && isset(self::$verified[$this]);
    }

    public function withAddress(string $address): self
    {
        if (! in_array($address, $this->addresses, true)) {
            throw new InvalidArgumentException('Address must belong to the resolved candidate set.');
        }

        $result = new self($this->url, $address, $this->addresses, $this->originalHost, $this->port, $this->targetId, $this->targetRevision, $this->destinationPolicyKey,
            targetOrigin: $this->targetOrigin);
        if ($this->isPolicyVerified()) {
            self::$verified ??= new WeakMap;
            self::$verified[$result] = true;
        }

        return $result;
    }
}
