<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;
use LogicException;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Health\HealthEpochMap;

final readonly class AuthorizedWireSnapshot
{
    public function __construct(
        public Uuid $permitId,
        public Uuid $routeId,
        public RouteKind $routeKind,
        public ?Uuid $endpointId,
        public int $credentialVersion,
        public ResolvedUrl $verifiedDestination,
        public EgressIdentity $egress,
        public CanonicalOrigin $origin,
        public string $interpretationPolicyKey,
        public HealthEpochMap $healthEpochs,
        public OwnerRef $owner,
        public ContentHash $fullFingerprint,
        public int $latestStartMonotonicNs,
    ) {
        if (($routeKind === RouteKind::Proxy) !== ($endpointId !== null)) {
            throw new InvalidArgumentException('Authorized wire snapshot route kind and endpointId must satisfy strict XOR.');
        }

        if ($credentialVersion < 1) {
            throw new InvalidArgumentException('Authorized wire snapshot credentialVersion must be at least 1.');
        }

        if (! $verifiedDestination->url->origin->equals($origin)) {
            throw new InvalidArgumentException('Verified destination origin must match the snapshot canonical origin.');
        }

        if (preg_match('/^[a-z0-9][a-z0-9_.:-]{0,63}$/D', $interpretationPolicyKey) !== 1) {
            throw new InvalidArgumentException('Interpretation policy key must be a valid canonical identifier.');
        }

        if ($latestStartMonotonicNs <= 0) {
            throw new InvalidArgumentException('Authorized wire snapshot requires a positive finite monotonic latestStart.');
        }
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('AuthorizedWireSnapshot cannot be serialized.');
    }
}
