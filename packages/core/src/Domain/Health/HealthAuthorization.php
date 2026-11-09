<?php

declare(strict_types=1);

namespace Proxyma\Domain\Health;

use InvalidArgumentException;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Execution\Uuid;

final readonly class HealthAuthorization
{
    public function __construct(
        public RouteKind $routeKind,
        public int $credentialVersion,
        public EgressIdentity $egress,
        public ?string $independenceGroup = null,
        public ?Uuid $probeToken = null,
    ) {
        if ($credentialVersion < 1) {
            throw new InvalidArgumentException('Credential version in health authorization must be at least 1.');
        }

        if ($independenceGroup !== null && preg_match('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D', $independenceGroup) !== 1) {
            throw new InvalidArgumentException('Independence group in health authorization must be a valid canonical identifier.');
        }
    }
}
