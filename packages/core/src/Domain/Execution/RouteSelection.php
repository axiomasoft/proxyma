<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\RouteKind;

final readonly class RouteSelection
{
    public function __construct(
        public Uuid $routeId,
        public RouteKind $kind,
        public ?Uuid $profileId,
        public ?Uuid $endpointId,
        public int $credentialVersion,
        public EgressIdentity $egress,
        public ?string $independenceGroup = null,
    ) {
        if (($kind === RouteKind::Proxy) !== ($endpointId !== null)) {
            throw new InvalidArgumentException('Selected route kind and endpointId must satisfy strict XOR.');
        }

        if ($credentialVersion < 1) {
            throw new InvalidArgumentException('Selected route credentialVersion must be at least 1.');
        }
    }
}
